<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\VoucherApprovalMail;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Scopes\BranchScope;
use App\Models\StaffSalary;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherApproval;
use App\Support\BranchAttribution;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vouchers / bons de caisse (docs/plans/cash-controls.md, wave 5) — every
 * franc that leaves the company, and since
 * docs/plans/expenditures-and-spending-controls.md (E1) the ONLY writer of
 * Expenditure rows: paying an expense bon, settling an advance, or
 * correcting a paid bon.
 *
 * Who approves (decided at request time, re-checked at every decision AND
 * at pay, under one advisory lock so concurrent approvals/payouts can't race
 * past a limit):
 *   - standard     — one holder of `vouchers.approve` who is not the
 *                    requester.
 *   - stakeholders — the amount is above the single-bon limit; or the bons
 *                    paid today (Cameroon time, by the day they are PAID —
 *                    E8, so splitting or future-dating can't dodge it) plus
 *                    the ones approved and waiting plus this one go above
 *                    the daily limit; or the month's committed spend plus
 *                    this one goes above the monthly cap (E2–E4). Then EVERY
 *                    investor must approve (the requester excluded); with no
 *                    investors, one owner (super) approval suffices. Any
 *                    single rejection rejects.
 *
 * Who pays: a holder of `vouchers.pay` who is not the requester (the owner
 * excepted), from an account that actually holds the money. Paying an
 * expense writes the Expenditure; an advance is settled later — what was
 * spent becomes the Expenditure, the change goes back into the till.
 */
class VoucherService
{
    /** The expense category salary bons are booked under (wave 4). */
    public const string SALARY_CATEGORY = 'Salaries';

    public const string STAGE_APPROVE = 'approve';

    public const string STAGE_PAY = 'pay';

    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CashBookService $cashBook,
        private readonly ExpenditureService $expenditures,
        private readonly SpendingControlService $spending,
        private readonly NotificationService $notifications,
        private readonly CashNotificationService $cashNotifier,
        private readonly ActionAlertService $actionAlerts,
    ) {}

    /**
     * Which tier a bon of $amount needs right now.
     *
     * At request / approval the pay day is not known yet, so the daily total
     * counts the bons paid today plus every approved bon still waiting to be
     * paid. At pay it is exact: the bons already paid today plus this one.
     *
     * @return array{tier: string, reasons: list<string>}
     */
    public function tierFor(string $amount, ?int $excludeVoucherId = null, string $stage = self::STAGE_APPROVE): array
    {
        $company = Company::cached();
        $reasons = [];

        if ($company?->voucher_approval_limit !== null && bccomp($amount, (string) $company->voucher_approval_limit, 2) > 0) {
            $reasons[] = 'above_single_limit';
        }

        if ($company?->voucher_daily_limit !== null) {
            $committed = $stage === self::STAGE_APPROVE
                ? $this->committedToday($excludeVoucherId)
                : $this->paidToday($excludeVoucherId);

            if (bccomp(bcadd($committed, $amount, 2), (string) $company->voucher_daily_limit, 2) > 0) {
                $reasons[] = 'above_daily_limit';
            }
        }

        if ($this->spending->exceedsCap($amount, $excludeVoucherId)) {
            $reasons[] = 'above_monthly_cap';
        }

        return ['tier' => $reasons === [] ? Voucher::TIER_STANDARD : Voucher::TIER_STAKEHOLDERS, 'reasons' => $reasons];
    }

    /**
     * Today's company-wide commitment against the daily limit: bons paid out
     * today plus bons approved and waiting to be paid. Company-wide whoever
     * asks (branches v2 D10) — a branch never gets its own daily allowance.
     */
    public function committedToday(?int $excludeVoucherId = null): string
    {
        return bcadd($this->paidToday($excludeVoucherId), (string) Voucher::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('status', 'approved')
            ->when($excludeVoucherId !== null, fn ($query) => $query->whereKeyNot($excludeVoucherId))
            ->sum('amount'), 2);
    }

    /** Total of the bons paid out today, Cameroon time, company-wide (D10). */
    public function paidToday(?int $excludeVoucherId = null): string
    {
        [$start, $end] = BusinessTimezone::dayBoundsUtc(BusinessTimezone::today());

        return bcadd((string) Voucher::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('status', ['paid', 'settled'])
            ->whereBetween('paid_at', [$start, $end])
            ->when($excludeVoucherId !== null, fn ($query) => $query->whereKeyNot($excludeVoucherId))
            ->sum('amount'), '0', 2);
    }

    /**
     * Who must approve a stakeholders-tier voucher: every investor, or —
     * when there are none — any one owner (super).
     *
     * @return array{ids: list<int>, mode: string}
     */
    public function stakeholderApprovers(?int ...$excludedUserIds): array
    {
        $tenantId = (string) tenant()?->getTenantKey();

        $investors = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('is_investor', true)
            ->whereNotIn('user_id', array_filter($excludedUserIds))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($investors !== []) {
            return ['ids' => $investors, 'mode' => 'all'];
        }

        $superRoles = Role::query()->where('is_super', true)->pluck('name');

        return [
            'ids' => TenantUser::query()->where('tenant_id', $tenantId)->whereIn('role', $superRoles)->whereNotIn('user_id', array_filter($excludedUserIds))->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'mode' => 'any',
        ];
    }

    /**
     * Every bon carries a category (E1) so every franc out reaches the P&L:
     * an expense at request, an advance at the latest when it is settled.
     *
     * @param  array{kind: string, payee_name: string, purpose: string, category_uuid?: ?string, amount: string, needed_on?: ?string, from_collections?: bool, local_uuid?: ?string, recorded_by_device?: ?string, origin?: ?string, salary_user_id?: ?int, salary_period?: ?string, payee_user_id?: ?int}  $data
     * @param  ?int  $branchId  a bon the system raises for a customer (a refund) belongs to the customer's branch; otherwise the requester's (branches v2)
     */
    public function request(array $data, User $requester, ?UploadedFile $attachment = null, ?int $branchId = null): Voucher
    {
        $categoryId = isset($data['category_uuid']) ? ExpenseCategory::query()->where('uuid', $data['category_uuid'])->value('id') : null;

        if ($data['kind'] === Voucher::KIND_EXPENSE && $categoryId === null) {
            throw ValidationException::withMessages(['category_uuid' => 'Choose the expense category — every bon must reach the P&L.']);
        }

        $fromCollections = (bool) ($data['from_collections'] ?? false);

        if ($fromCollections && $data['kind'] !== Voucher::KIND_EXPENSE) {
            throw ValidationException::withMessages(['from_collections' => 'Only an expense already paid can come out of your collections.']);
        }

        $tier = $this->tierFor($data['amount']);
        $approvers = $tier['tier'] === Voucher::TIER_STAKEHOLDERS ? $this->stakeholderApprovers($requester->id, $data['payee_user_id'] ?? null, $data['salary_user_id'] ?? null) : ['ids' => [], 'mode' => 'any'];

        // Nobody approves their own bon, the owner included (wave 4): above
        // the limits someone else must exist to approve it.
        if ($tier['tier'] === Voucher::TIER_STAKEHOLDERS && $approvers['ids'] === []) {
            throw ValidationException::withMessages(['amount' => 'This bon is above the limits and nobody else can approve it — add an investor or a second owner first.']);
        }

        $voucher = DB::transaction(fn (): Voucher => Voucher::query()->create([
            'number' => $this->documentNumbers->next(DocumentNumberService::VOUCHER),
            'kind' => $data['kind'],
            'payee_name' => $data['payee_name'],
            'purpose' => $data['purpose'],
            'expense_category_id' => $categoryId,
            'amount' => $data['amount'],
            'needed_on' => $data['needed_on'] ?? BusinessTimezone::today(),
            'status' => 'pending',
            'approval_tier' => $tier['tier'],
            'approval_mode' => $approvers['mode'],
            'tier_reasons' => $tier['reasons'],
            'required_approver_ids' => $approvers['ids'],
            'attachment_path' => $attachment?->store('vouchers', $this->disk()),
            'requested_by' => $requester->id,
            'branch_id' => $branchId ?? BranchAttribution::forUser($requester),
            'from_collections' => $fromCollections,
            'local_uuid' => $data['local_uuid'] ?? null,
            'recorded_by_device' => $data['recorded_by_device'] ?? null,
            'origin' => $data['origin'] ?? null,
            'salary_user_id' => $data['salary_user_id'] ?? null,
            'salary_period' => $data['salary_period'] ?? null,
            'payee_user_id' => $data['payee_user_id'] ?? null,
        ]));

        $this->notifyApprovers($voucher);

        return $voucher;
    }

    /**
     * Whether $user may approve/reject this voucher right now — the single
     * rule VoucherPolicy and the service both use.
     */
    public function canDecide(Voucher $voucher, User $user, TenantContext $context): bool
    {
        if (! $voucher->isPending() || $voucher->approvals()->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($voucher->needsStakeholders()) {
            return in_array($user->id, $voucher->requiredApproverIds(), true) && ! $this->isOwn($voucher, $user);
        }

        return $context->can('vouchers.approve') && ! $this->isOwn($voucher, $user);
    }

    /**
     * @return string `approved` (fully approved), `recorded` (this approval counted, others
     *                still needed) or `escalated` (a limit is now exceeded — every
     *                stakeholder must approve instead, and they have been asked)
     */
    public function approve(Voucher $voucher, User $user, ?string $note): string
    {
        $this->assertNotOwn($voucher, $user);

        return DB::transaction(function () use ($voucher, $user, $note): string {
            $this->lockLimits();
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            // Re-check the limits now: other bons approved or paid since this
            // one was requested may have used up the day or the month.
            if (! $locked->needsStakeholders()) {
                $tier = $this->tierFor((string) $locked->amount, $locked->id);

                if ($tier['tier'] === Voucher::TIER_STAKEHOLDERS) {
                    $this->escalate($locked, $tier['reasons']);

                    return 'escalated';
                }
            }

            VoucherApproval::query()->create(['voucher_id' => $locked->id, 'user_id' => $user->id, 'decision' => 'approved', 'note' => $note, 'decided_at' => now()]);

            if (! $this->isFullyApproved($locked)) {
                return 'recorded';
            }

            $locked->update(['status' => 'approved', 'approved_at' => now()]);
            DB::afterCommit(fn () => $this->notifyApproved($locked));

            return 'approved';
        });
    }

    public function reject(Voucher $voucher, User $user, string $reason): Voucher
    {
        $this->assertNotOwn($voucher, $user);

        return DB::transaction(function () use ($voucher, $user, $reason): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['reason' => "{$locked->number} is {$locked->status} — only a waiting bon is rejected."]);
            }

            if ($locked->isLocked()) {
                throw ValidationException::withMessages(['reason' => "{$locked->number} pays back money already taken off a payment — it is only paid, never rejected."]);
            }

            VoucherApproval::query()->create(['voucher_id' => $locked->id, 'user_id' => $user->id, 'decision' => 'rejected', 'note' => $reason, 'decided_at' => now()]);
            $locked->update(['status' => 'rejected', 'rejected_by' => $user->id, 'rejection_reason' => $reason]);

            DB::afterCommit(fn () => $this->notifyRequester($locked, 'voucher.rejected', 'warning', 'Voucher rejected', "{$locked->number} was rejected by {$user->name}: {$reason}"));

            return $locked;
        });
    }

    /**
     * A month's salary for one person (owner, 2026-10-28, wave 4): never for
     * a future month or someone inactive; approved and paid like any bon —
     * never by the person being paid.
     *
     * Salary advances (owner, 2026-10-30): the month may be paid in parts.
     * $amount defaults to what is left of the approved salary; the month's
     * live salary bons never add up to more than it. Checked under a lock on
     * the person's salary row, so two requests can't both take the rest.
     */
    public function requestSalary(User $payee, string $period, User $requester, ?string $amount = null): Voucher
    {
        return DB::transaction(fn (): Voucher => $this->requestSalaryLocked($payee, $period, $requester, $amount));
    }

    /** What is left of $payee's approved salary for $period, after the month's live salary bons. */
    public function salaryLeft(User $payee, string $period): ?string
    {
        $salary = StaffSalary::query()->where('user_id', $payee->id)->where('status', StaffSalary::STATUS_APPROVED)->latest('id')->first();

        return $salary === null ? null : bcsub((string) $salary->amount, $this->salaryBonsTotal($payee, $period), 2);
    }

    private function salaryBonsTotal(User $payee, string $period): string
    {
        return number_format((float) Voucher::query()->withoutGlobalScope(BranchScope::class)
            ->where('salary_user_id', $payee->id)->where('salary_period', $period)
            ->whereNotIn('status', ['rejected', 'cancelled'])->sum('amount'), 2, '.', '');
    }

    private function requestSalaryLocked(User $payee, string $period, User $requester, ?string $amount): Voucher
    {
        if ($period > BusinessTimezone::now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'A salary is paid for this month or one already past, never ahead.']);
        }

        if ($payee->status !== 'active') {
            throw ValidationException::withMessages(['period' => "{$payee->name} is not active — no salary is paid."]);
        }

        $salary = StaffSalary::query()->where('user_id', $payee->id)->where('status', StaffSalary::STATUS_APPROVED)->latest('id')->lockForUpdate()->first();

        if ($salary === null) {
            throw ValidationException::withMessages(['period' => "{$payee->name} has no approved salary — set it first."]);
        }

        $left = bcsub((string) $salary->amount, $this->salaryBonsTotal($payee, $period), 2);

        if (bccomp($left, '0', 2) <= 0) {
            throw ValidationException::withMessages(['period' => "{$payee->name}'s salary for {$period} is already fully on bons."]);
        }

        $amount ??= $left;

        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $left, 2) > 0) {
            throw ValidationException::withMessages(['amount' => 'At most '.number_format((float) $left, 0, '.', ' ')." FCFA of {$payee->name}'s salary for {$period} is left to pay."]);
        }

        $category = ExpenseCategory::query()->firstOrCreate(['name' => self::SALARY_CATEGORY], ['icon' => 'ti-users', 'is_active' => true]);
        $isPart = bccomp($amount, (string) $salary->amount, 2) < 0;

        return $this->request([
            'kind' => Voucher::KIND_EXPENSE,
            'payee_name' => mb_substr((string) $payee->name, 0, 150),
            'purpose' => ($isPart ? 'Salary (part)' : 'Salary')." — {$payee->name}, {$period}",
            'category_uuid' => $category->uuid,
            'amount' => $amount,
            'origin' => Voucher::ORIGIN_SALARY,
            'salary_user_id' => $payee->id,
            'salary_period' => $period,
        ], $requester, branchId: $salary->branch_id);
    }

    /** The requester, or the person the bon pays (a salary, or staff chosen as payee). */
    public function isOwn(Voucher $voucher, User $user): bool
    {
        return in_array($user->id, [$voucher->requested_by, $voucher->salary_user_id, $voucher->payee_user_id], true);
    }

    /** Nobody decides, pays or settles their own bon — the owner included (owner, 2026-10-29). */
    private function assertNotOwn(Voucher $voucher, User $user, string $field = 'voucher'): void
    {
        if ($this->isOwn($voucher, $user)) {
            throw ValidationException::withMessages([$field => "{$voucher->number} is your own bon — someone else approves, pays or settles it."]);
        }
    }

    public function cancel(Voucher $voucher): Voucher
    {
        if ($voucher->isLocked()) {
            throw ValidationException::withMessages(['voucher' => "{$voucher->number} pays back money already taken off a payment — it is only paid, never cancelled."]);
        }

        $voucher->update(['status' => 'cancelled']);

        return $voucher;
    }

    /**
     * Hands the money over. The account must hold it; the movement and (for
     * an expense) the Expenditure are written in the same transaction. The
     * limits are re-checked first using today as the pay day: if this payout
     * would take the day or the month over its limit, the bon goes back to
     * every stakeholder instead of being paid.
     */
    public function pay(Voucher $voucher, User $payer, CashAccount $from, string $method, ?string $reference, string $receivedByName, ?UploadedFile $signedCopy): Voucher
    {
        if ($signedCopy === null && $voucher->needsSignedCopyAtPay()) {
            throw ValidationException::withMessages(['signed_copy' => 'Add a photo of the bon signed by '.($voucher->origin === Voucher::ORIGIN_SALARY ? 'the staff member' : 'the customer').' — a refund or salary is paid only against their signature.']);
        }

        $outcome = DB::transaction(fn (): string => $this->payLocked($voucher, $payer, $from, $method, $reference, $receivedByName, $signedCopy));

        if ($outcome === 'escalated') {
            throw ValidationException::withMessages(['account' => "Paying {$voucher->number} now would take today's or this month's payouts over the limit — every stakeholder must approve it first, and they have been asked."]);
        }

        return $voucher->refresh();
    }

    /**
     * A "spent from my collections" bon, paid by the cashier as part of
     * receiving the collector's handover: the collected cash the agent
     * already spent goes into the till and straight back out on the bon, so
     * the drawer count still matches. Runs inside the caller's transaction.
     *
     * @return bool false when the re-check sent the bon back to the
     *              stakeholders — it then stays unpaid and leaves the handover
     */
    public function payAtHandover(Voucher $voucher, User $receiver, CashAccount $till, string $collectorName): bool
    {
        $this->cashBook->record($till, 'in', (string) $voucher->amount, CashMovement::KIND_REMITTANCE_CASH, $receiver, [
            'document_number' => $voucher->number,
            'source' => $voucher,
            'note' => "Collected cash {$collectorName} already spent on {$voucher->number}",
        ]);

        $outcome = $this->payLocked($voucher, $receiver, $till, 'cash', null, $collectorName, null);

        if ($outcome === 'escalated') {
            // Undo the matching cash-in: the bon was not paid after all.
            $this->cashBook->record($till, 'out', (string) $voucher->amount, CashMovement::KIND_REMITTANCE_CASH, $receiver, [
                'document_number' => $voucher->number,
                'source' => $voucher,
                'note' => "{$voucher->number} needs every stakeholder's approval — not paid at this handover",
            ]);

            Voucher::query()->whereKey($voucher->id)->update(['remittance_id' => null, 'from_collections' => false]);

            return false;
        }

        return true;
    }

    /**
     * Advances past their settle date with nothing accounted for (owner,
     * 2026-10-30): the whole amount becomes the requester's shortage. The
     * requester can still settle with late receipts (settle() then closes
     * it); repaying or writing the shortage off closes the advance
     * (ShortageService::settle()). Daily, vouchers:advances-overdue.
     *
     * @return int how many advances became shortages
     */
    public function openOverdueAdvanceShortages(): int
    {
        $overdue = Voucher::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', Voucher::KIND_ADVANCE)->where('status', 'paid')
            ->whereDate('settle_due_on', '<', BusinessTimezone::today())
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cash_shortages')
                ->whereColumn('cash_shortages.source_id', 'vouchers.id')
                ->where('cash_shortages.source_type', (new Voucher)->getMorphClass())
                ->where('cash_shortages.kind', CashShortage::KIND_ADVANCE))
            ->get();

        foreach ($overdue as $voucher) {
            $recorder = User::query()->find($voucher->paid_by ?? $voucher->requested_by);

            app(ShortageService::class)->open($voucher->advanceHolderId(), $voucher->branch_id, (string) $voucher->amount, CashShortage::KIND_ADVANCE, $voucher, $recorder,
                "{$voucher->number}: not justified by ".$voucher->settle_due_on?->toDateString().' — the whole advance is owed until receipts or the cash come back');
        }

        return $overdue->count();
    }

    /**
     * Justifies a cash advance: what was spent (backed by a photo of its
     * receipts, becomes the Expenditure) plus the change returned. Whatever
     * neither covers is the requester's shortage (owner, 2026-10-29: whoever
     * last held the money owns the gap) — never more than the advance. An
     * advance requested without a category gets it now.
     */
    public function settle(Voucher $voucher, User $actor, string $spent, string $returned, ?string $note, ?string $categoryUuid = null, ?UploadedFile $receipt = null): Voucher
    {
        $this->assertNotOwn($voucher, $actor, 'spent_amount');

        if (bccomp(bcadd($spent, $returned, 2), (string) $voucher->amount, 2) > 0) {
            throw ValidationException::withMessages(['spent_amount' => 'What was spent plus the change returned cannot be more than the advance of '.number_format((float) $voucher->amount, 0, '.', ' ').' FCFA.']);
        }

        if (bccomp($spent, '0', 2) > 0 && $receipt === null) {
            throw ValidationException::withMessages(['receipt' => 'Add a photo of the receipts for what was spent.']);
        }

        return DB::transaction(function () use ($voucher, $actor, $spent, $returned, $note, $categoryUuid, $receipt): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== Voucher::KIND_ADVANCE || $locked->status !== 'paid') {
                throw ValidationException::withMessages(['spent_amount' => 'Only a paid cash advance can be settled.']);
            }

            // Receipts brought after the deadline: the overdue shortage gives
            // way to what this settlement finds missing (opened below).
            CashShortage::query()->open()->where('kind', CashShortage::KIND_ADVANCE)
                ->where('source_type', $locked->getMorphClass())->where('source_id', $locked->id)
                ->lockForUpdate()->get()
                ->each(fn (CashShortage $overdue) => $overdue->update([
                    'status' => CashShortage::STATUS_JUSTIFIED,
                    'settled_by' => $actor->id,
                    'settled_at' => now(),
                    'settled_note' => 'Receipts brought late — settled on '.BusinessTimezone::today(),
                ]));

            if ($categoryUuid !== null) {
                $locked->expense_category_id = ExpenseCategory::query()->where('uuid', $categoryUuid)->value('id');
            }

            if ($locked->expense_category_id === null && bccomp($spent, '0', 2) > 0) {
                throw ValidationException::withMessages(['category_uuid' => 'Choose the expense category of what was spent.']);
            }

            if (bccomp($returned, '0', 2) > 0) {
                $till = $this->cashBook->tillFor($actor);

                // Branches v2 §4.7: the change goes back into the bon's own branch.
                if ($till->branch_id !== $locked->branch_id) {
                    throw ValidationException::withMessages(['returned_amount' => "The change from {$locked->number} goes back to its own branch — settle it there."]);
                }

                $this->cashBook->record($till, 'in', $returned, CashMovement::KIND_ADVANCE_RETURN, $actor, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'note' => 'Change returned from advance',
                ]);
            }

            if ($receipt !== null) {
                // Before the Expenditure is written: it carries the bon's receipt.
                $locked->receipt_path = $receipt->store('vouchers', $this->disk());
            }

            $expenditure = bccomp($spent, '0', 2) > 0
                ? $this->writeExpenditure($locked, $spent, (string) $locked->received_by_name, $locked->paid_at)
                : null;

            $unaccounted = bcsub((string) $locked->amount, bcadd($spent, $returned, 2), 2);

            if (bccomp($unaccounted, '0', 2) > 0) {
                app(ShortageService::class)->open($locked->advanceHolderId(), $locked->branch_id, $unaccounted, CashShortage::KIND_ADVANCE, $locked, $actor,
                    "{$locked->number}: advance not accounted for by receipts or change".($note ? " — {$note}" : ''));
            }

            $locked->update([
                'status' => 'settled',
                'expense_category_id' => $locked->expense_category_id,
                'spent_amount' => $spent,
                'returned_amount' => $returned,
                'settled_by' => $actor->id,
                'settled_at' => now(),
                'settlement_note' => $note,
                'expenditure_id' => $expenditure?->id,
            ]);

            return $locked;
        });
    }

    /**
     * Corrects a paid / settled bon (item 9; admin or owner, never its
     * requester — VoucherPolicy::correct). Nothing in the past changes:
     *   - `returned`  — money came back: a cash-in to the corrector's till
     *                   and a negative adjustment expenditure in the current
     *                   month, pointing at the original;
     *   - `underpaid` — the payee is owed more: a new bon for the difference,
     *                   approved and paid like any other.
     *
     * @return array{outcome: string, voucher: Voucher, adjustment: ?Expenditure}
     */
    public function correct(Voucher $voucher, User $actor, string $outcome, string $amount, string $reason): array
    {
        if (! in_array($voucher->status, ['paid', 'settled'], true)) {
            throw ValidationException::withMessages(['amount' => 'Only a paid bon can be corrected.']);
        }

        if ($outcome === 'underpaid') {
            $topUp = $this->request([
                'kind' => Voucher::KIND_EXPENSE,
                'payee_name' => $voucher->payee_name,
                'purpose' => "Top-up of {$voucher->number}: {$reason}",
                'category_uuid' => $voucher->category?->uuid,
                'amount' => $amount,
            ], $actor);

            return ['outcome' => $outcome, 'voucher' => $topUp, 'adjustment' => null];
        }

        return DB::transaction(function () use ($voucher, $actor, $amount, $reason): array {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $original = Expenditure::query()->whereKey($locked->expenditure_id)->first();

            if ($original === null) {
                throw ValidationException::withMessages(['amount' => "{$locked->number} has no expenditure to correct."]);
            }

            $net = (string) Expenditure::query()->counted()->where('voucher_id', $locked->id)->sum('amount');

            if (bccomp($amount, $net, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'At most '.number_format((float) $net, 0, '.', ' ').' FCFA can come back on this bon.']);
            }

            $this->cashBook->record($this->cashBook->tillFor($actor), 'in', $amount, CashMovement::KIND_BON_CORRECTION, $actor, [
                'document_number' => $locked->number,
                'source' => $locked,
                'note' => "Returned on {$locked->number}: {$reason}",
            ]);

            $today = BusinessTimezone::today();

            $adjustment = Expenditure::query()->create([
                'category_id' => $original->category_id,
                'user_id' => $actor->id,
                'amount' => bcmul($amount, '-1', 2),
                'description' => "Correction of {$locked->number}: {$reason}",
                'spent_at' => $today,
                'notes' => "Money returned on bon {$locked->number}",
                'voucher_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'source' => Expenditure::SOURCE_ADJUSTMENT,
                'corrects_expenditure_id' => $original->id,
                'relates_to_period' => $original->period() !== BusinessTimezone::periodOf($today) ? $original->period() : null,
                'edit_reason' => $reason,
            ]);

            DB::afterCommit(fn () => $this->expenditures->forgetCaches($today));

            return ['outcome' => 'returned', 'voucher' => $locked, 'adjustment' => $adjustment];
        });
    }

    /**
     * The pay step with the bon row and the limits locked. Escalation is
     * committed (not rolled back) so the stakeholders' request stands.
     *
     * @return string `paid` or `escalated`
     */
    private function payLocked(Voucher $voucher, User $payer, CashAccount $from, string $method, ?string $reference, string $receivedByName, ?UploadedFile $signedCopy): string
    {
        $this->lockLimits();
        $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'approved') {
            throw ValidationException::withMessages(['account' => "Voucher {$locked->number} is {$locked->status}, not approved."]);
        }

        $this->assertNotOwn($locked, $payer, 'account');

        // Branches v2 §4.7: no joint account — a bon is paid from its own branch's money.
        if ($from->branch_id !== $locked->branch_id) {
            throw ValidationException::withMessages(['account' => "Pay {$locked->number} from an account of its own branch — send money between branches with a branch transfer."]);
        }

        if (! $locked->needsStakeholders()) {
            $tier = $this->tierFor((string) $locked->amount, $locked->id, self::STAGE_PAY);

            if ($tier['tier'] === Voucher::TIER_STAKEHOLDERS) {
                $this->escalate($locked, $tier['reasons']);

                // An owner who already approved it as the standard approver
                // has nothing left to approve — it may still be paid.
                if (! $this->isFullyApproved($locked)) {
                    $locked->update(['status' => 'pending', 'approved_at' => null]);

                    return 'escalated';
                }
            }
        }

        if ($locked->kind === Voucher::KIND_EXPENSE && $locked->expense_category_id === null) {
            throw ValidationException::withMessages(['account' => "Give {$locked->number} an expense category before paying it."]);
        }

        CashAccount::query()->whereKey($from->id)->lockForUpdate()->first();

        if (bccomp($this->cashBook->balance($from), (string) $locked->amount, 2) < 0) {
            throw ValidationException::withMessages(['account' => "{$from->name} does not hold enough to pay this voucher."]);
        }

        $this->cashBook->record($from, 'out', (string) $locked->amount, CashMovement::KIND_VOUCHER_PAYOUT, $payer, [
            'document_number' => $locked->number,
            'source' => $locked,
            'reference' => $reference,
            'note' => "Paid to {$receivedByName}",
        ]);

        $attributes = [
            'status' => 'paid',
            'paid_by' => $payer->id,
            'paid_at' => now(),
            'paid_from_account_id' => $from->id,
            'payment_method' => $method,
            'payment_reference' => $reference,
            'received_by_name' => $receivedByName,
            'signed_copy_path' => $signedCopy?->store('vouchers', $this->disk()),
        ];

        if ($locked->kind === Voucher::KIND_EXPENSE) {
            $attributes['expenditure_id'] = $this->writeExpenditure($locked, (string) $locked->amount, $receivedByName)->id;
        } else {
            $attributes['settle_due_on'] = BusinessTimezone::now()->addDays((int) (Company::cached()?->advance_settlement_days ?? 7))->toDateString();
        }

        $locked->update($attributes);

        return 'paid';
    }

    /**
     * Serialises every limit check of this workspace: two approvals or
     * payouts can't both read "under the limit" and both go through.
     * Transaction-scoped Postgres advisory lock, keyed per tenant.
     */
    private function lockLimits(): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['voucher-limits:'.(string) tenant()?->getTenantKey()]);
    }

    /**
     * @param  list<string>  $reasons
     */
    private function escalate(Voucher $locked, array $reasons): void
    {
        $approvers = $this->stakeholderApprovers($locked->requested_by, $locked->payee_user_id, $locked->salary_user_id);

        $locked->update([
            'approval_tier' => Voucher::TIER_STAKEHOLDERS,
            'approval_mode' => $approvers['mode'],
            'tier_reasons' => $reasons,
            'required_approver_ids' => $approvers['ids'],
        ]);

        DB::afterCommit(fn () => $this->notifyApprovers($locked));
    }

    private function isFullyApproved(Voucher $voucher): bool
    {
        if (! $voucher->needsStakeholders()) {
            return true;
        }

        $approvedBy = $voucher->approvals()->where('decision', 'approved')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $required = $voucher->requiredApproverIds();

        if ($voucher->approval_mode === 'any') {
            return array_intersect($required, $approvedBy) !== [];
        }

        // Every stakeholder who is still an investor must have approved.
        $stillRequired = array_values(array_intersect($required, $this->stakeholderApprovers($voucher->requested_by, $voucher->payee_user_id, $voucher->salary_user_id)['ids']));

        return $stillRequired === [] || array_diff($stillRequired, $approvedBy) === [];
    }

    /**
     * The one place an Expenditure is born (E1). Dated today on the Cameroon
     * clock; an advance settled in a later month than it was paid keeps a
     * pointer to that month (relates_to_period).
     */
    private function writeExpenditure(Voucher $voucher, string $amount, string $receivedByName, ?Carbon $paidAt = null): Expenditure
    {
        $today = BusinessTimezone::today();
        $paidPeriod = $paidAt !== null ? BusinessTimezone::periodOf($paidAt) : null;

        $expenditure = Expenditure::query()->create([
            'category_id' => $voucher->expense_category_id,
            'user_id' => $voucher->requested_by,
            'amount' => $amount,
            'description' => "{$voucher->number}: {$voucher->purpose}",
            'spent_at' => $today,
            'notes' => "Paid to {$receivedByName} on voucher {$voucher->number}",
            'receipt_path' => $voucher->receipt_path,
            'voucher_id' => $voucher->id,
            'branch_id' => $voucher->branch_id,
            'source' => Expenditure::SOURCE_VOUCHER,
            'relates_to_period' => $paidPeriod !== null && $paidPeriod !== BusinessTimezone::periodOf($today) ? $paidPeriod : null,
        ]);

        DB::afterCommit(fn () => $this->expenditures->forgetCaches($today));

        return $expenditure;
    }

    private function notifyApprovers(Voucher $voucher): void
    {
        $voucher->loadMissing('requestedBy');
        $amount = number_format((float) $voucher->amount, 0, '.', ' ').' FCFA';
        $body = "{$voucher->requestedBy?->name} requests {$amount} for {$voucher->payee_name}: {$voucher->purpose}";

        // Money waiting: an urgent alert to each person who can approve it
        // (the manager inbox, docs/plans/mobile-manager-app.md wave 2).
        if (! $voucher->needsStakeholders()) {
            $this->actionAlerts->alert(
                $this->actionAlerts->deciders('vouchers.approve', $voucher->branch_id, (int) $voucher->requested_by),
                'inbox.voucher',
                'urgent',
                "Voucher {$voucher->number} to approve",
                $body,
                "/vouchers/{$voucher->uuid}",
                'voucher',
                $voucher->uuid,
                $voucher->branch_id,
            );

            return;
        }

        foreach (User::query()->whereIn('id', $voucher->requiredApproverIds())->get() as $approver) {
            $this->notifications->broadcastToUser($approver, 'voucher.stakeholder_approval', 'urgent', "Your approval is needed: {$voucher->number}", $body, "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);

            try {
                Mail::to($approver->email)->queue(new VoucherApprovalMail($voucher, $approver->name));
            } catch (Throwable $e) {
                // Email is best effort — the in-app notification above still reaches them.
                Log::warning('Voucher approval email failed', ['voucher' => $voucher->number, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyApproved(Voucher $voucher): void
    {
        $this->notifyRequester($voucher, 'voucher.approved', 'info', 'Voucher approved', $voucher->from_collections
            ? "{$voucher->number} is approved — it will be settled at your next cash handover."
            : "{$voucher->number} is approved — the cashier can pay it.");

        if (! $voucher->from_collections) {
            $this->cashNotifier->toHoldersOf('vouchers.pay', 'voucher.approved', 'info', "Voucher {$voucher->number} ready to pay", "{$voucher->payee_name}: ".number_format((float) $voucher->amount, 0, '.', ' ').' FCFA', "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);
        }
    }

    private function notifyRequester(Voucher $voucher, string $type, string $severity, string $title, string $body): void
    {
        $voucher->loadMissing('requestedBy');

        if ($voucher->requestedBy) {
            $this->notifications->broadcastToUser($voucher->requestedBy, $type, $severity, $title, $body, "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);
        }
    }

    public function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }
}
