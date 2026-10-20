<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExpenditureData;
use App\Mail\VoucherApprovalMail;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherApproval;
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
 * franc that leaves the company.
 *
 * Who approves (decided at request time, re-checked at every decision):
 *   - standard     — one holder of `vouchers.approve` who is not the
 *                    requester.
 *   - stakeholders — the amount is above the company's single-voucher
 *                    limit, or the approved/paid vouchers already due that
 *                    day plus this one go above the daily limit. Then EVERY
 *                    investor must approve (the requester excluded); with no
 *                    investors set up, one owner (super) approval suffices.
 *                    Any single rejection rejects.
 *
 * Who pays: a holder of `vouchers.pay` who is not the requester (the owner
 * excepted), from an account that actually holds the money. Paying an
 * expense writes the Expenditure; an advance is settled later — what was
 * spent becomes the Expenditure, the change goes back into the till.
 */
class VoucherService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CashBookService $cashBook,
        private readonly ExpenditureService $expenditures,
        private readonly NotificationService $notifications,
        private readonly CashNotificationService $cashNotifier,
    ) {}

    /**
     * @return array{tier: string, reasons: list<string>}
     */
    public function tierFor(string $amount, string $neededOn, ?int $excludeVoucherId = null): array
    {
        $company = Company::cached();
        $reasons = [];

        if ($company?->voucher_approval_limit !== null && bccomp($amount, (string) $company->voucher_approval_limit, 2) > 0) {
            $reasons[] = 'above_single_limit';
        }

        if ($company?->voucher_daily_limit !== null) {
            $committed = (string) Voucher::query()
                ->whereDate('needed_on', $neededOn)
                ->whereIn('status', ['approved', 'paid', 'settled'])
                ->when($excludeVoucherId !== null, fn ($query) => $query->whereKeyNot($excludeVoucherId))
                ->sum('amount');

            if (bccomp(bcadd($committed, $amount, 2), (string) $company->voucher_daily_limit, 2) > 0) {
                $reasons[] = 'above_daily_limit';
            }
        }

        return ['tier' => $reasons === [] ? Voucher::TIER_STANDARD : Voucher::TIER_STAKEHOLDERS, 'reasons' => $reasons];
    }

    /**
     * Who must approve a stakeholders-tier voucher: every investor, or —
     * when there are none — any one owner (super).
     *
     * @return array{ids: list<int>, mode: string}
     */
    public function stakeholderApprovers(int $requesterId): array
    {
        $tenantId = (string) tenant()?->getTenantKey();

        $investors = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('is_investor', true)
            ->where('user_id', '!=', $requesterId)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($investors !== []) {
            return ['ids' => $investors, 'mode' => 'all'];
        }

        $superRoles = Role::query()->where('is_super', true)->pluck('name');

        return [
            'ids' => TenantUser::query()->where('tenant_id', $tenantId)->whereIn('role', $superRoles)->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'mode' => 'any',
        ];
    }

    /**
     * @param  array{kind: string, payee_name: string, purpose: string, category_uuid?: ?string, amount: string, needed_on?: ?string}  $data
     */
    public function request(array $data, User $requester, ?UploadedFile $attachment = null): Voucher
    {
        $neededOn = $data['needed_on'] ?? $this->cashBook->today();
        $tier = $this->tierFor($data['amount'], $neededOn);
        $approvers = $tier['tier'] === Voucher::TIER_STAKEHOLDERS ? $this->stakeholderApprovers($requester->id) : ['ids' => [], 'mode' => 'any'];

        $voucher = DB::transaction(fn (): Voucher => Voucher::query()->create([
            'number' => $this->documentNumbers->next(DocumentNumberService::VOUCHER),
            'kind' => $data['kind'],
            'payee_name' => $data['payee_name'],
            'purpose' => $data['purpose'],
            'expense_category_id' => isset($data['category_uuid']) ? ExpenseCategory::query()->where('uuid', $data['category_uuid'])->value('id') : null,
            'amount' => $data['amount'],
            'needed_on' => $neededOn,
            'status' => 'pending',
            'approval_tier' => $tier['tier'],
            'approval_mode' => $approvers['mode'],
            'tier_reasons' => $tier['reasons'],
            'required_approver_ids' => $approvers['ids'],
            'attachment_path' => $attachment?->store('vouchers', $this->disk()),
            'requested_by' => $requester->id,
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
            return in_array($user->id, $voucher->requiredApproverIds(), true);
        }

        return $context->can('vouchers.approve') && ($voucher->requested_by !== $user->id || $context->isSuper());
    }

    /**
     * @return string `approved` (fully approved), `recorded` (this approval counted, others
     *                still needed) or `escalated` (the day's limit is now exceeded — every
     *                stakeholder must approve instead, and they have been asked)
     */
    public function approve(Voucher $voucher, User $user, ?string $note): string
    {
        return DB::transaction(function () use ($voucher, $user, $note): string {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            // Re-check the limits now: other vouchers approved since this one
            // was requested may have taken the day over its limit.
            if (! $locked->needsStakeholders()) {
                $tier = $this->tierFor((string) $locked->amount, $locked->needed_on->toDateString(), $locked->id);

                if ($tier['tier'] === Voucher::TIER_STAKEHOLDERS) {
                    $approvers = $this->stakeholderApprovers($locked->requested_by);
                    $locked->update([
                        'approval_tier' => Voucher::TIER_STAKEHOLDERS,
                        'approval_mode' => $approvers['mode'],
                        'tier_reasons' => $tier['reasons'],
                        'required_approver_ids' => $approvers['ids'],
                    ]);

                    DB::afterCommit(fn () => $this->notifyApprovers($locked));

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
        return DB::transaction(function () use ($voucher, $user, $reason): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            VoucherApproval::query()->create(['voucher_id' => $locked->id, 'user_id' => $user->id, 'decision' => 'rejected', 'note' => $reason, 'decided_at' => now()]);
            $locked->update(['status' => 'rejected', 'rejected_by' => $user->id, 'rejection_reason' => $reason]);

            DB::afterCommit(fn () => $this->notifyRequester($locked, 'voucher.rejected', 'warning', 'Voucher rejected', "{$locked->number} was rejected by {$user->name}: {$reason}"));

            return $locked;
        });
    }

    public function cancel(Voucher $voucher): Voucher
    {
        $voucher->update(['status' => 'cancelled']);

        return $voucher;
    }

    /**
     * Hands the money over. The account must hold it; the movement and (for
     * an expense) the Expenditure are written in the same transaction.
     */
    public function pay(Voucher $voucher, User $payer, CashAccount $from, string $method, ?string $reference, string $receivedByName, ?UploadedFile $signedCopy): Voucher
    {
        return DB::transaction(function () use ($voucher, $payer, $from, $method, $reference, $receivedByName, $signedCopy): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['account' => "Voucher {$locked->number} is {$locked->status}, not approved."]);
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
                $attributes['expenditure_id'] = $this->writeExpenditure($locked, (string) $locked->amount)?->id;
            } else {
                $attributes['settle_due_on'] = Carbon::today()->addDays((int) (Company::cached()?->advance_settlement_days ?? 7))->toDateString();
            }

            $locked->update($attributes);

            return $locked;
        });
    }

    /**
     * Justifies a cash advance: what was spent (backed by receipts, becomes
     * the Expenditure) plus the change returned must equal the advance.
     */
    public function settle(Voucher $voucher, User $actor, string $spent, string $returned, ?string $note): Voucher
    {
        if (bccomp(bcadd($spent, $returned, 2), (string) $voucher->amount, 2) !== 0) {
            throw ValidationException::withMessages(['spent_amount' => 'What was spent plus the change returned must equal the advance of '.number_format((float) $voucher->amount, 0, '.', ' ').' FCFA.']);
        }

        return DB::transaction(function () use ($voucher, $actor, $spent, $returned, $note): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== Voucher::KIND_ADVANCE || $locked->status !== 'paid') {
                throw ValidationException::withMessages(['spent_amount' => 'Only a paid cash advance can be settled.']);
            }

            if (bccomp($returned, '0', 2) > 0) {
                $this->cashBook->record($this->cashBook->tillFor($actor), 'in', $returned, CashMovement::KIND_ADVANCE_RETURN, $actor, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'note' => 'Change returned from advance',
                ]);
            }

            $locked->update([
                'status' => 'settled',
                'spent_amount' => $spent,
                'returned_amount' => $returned,
                'settled_by' => $actor->id,
                'settled_at' => now(),
                'settlement_note' => $note,
                'expenditure_id' => bccomp($spent, '0', 2) > 0 ? $this->writeExpenditure($locked, $spent)?->id : null,
            ]);

            return $locked;
        });
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
        $stillRequired = array_values(array_intersect($required, $this->stakeholderApprovers($voucher->requested_by)['ids']));

        return $stillRequired === [] || array_diff($stillRequired, $approvedBy) === [];
    }

    private function writeExpenditure(Voucher $voucher, string $amount): ?Expenditure
    {
        $category = $voucher->category;

        if ($category === null) {
            return null;
        }

        return $this->expenditures->create(ExpenditureData::fromArray([
            'category_uuid' => $category->uuid,
            'amount' => $amount,
            'description' => "{$voucher->number}: {$voucher->purpose}",
            'spent_at' => Carbon::today()->toDateString(),
            'notes' => "Paid to {$voucher->received_by_name} on voucher {$voucher->number}",
        ]), $voucher->requested_by);
    }

    private function notifyApprovers(Voucher $voucher): void
    {
        $voucher->loadMissing('requestedBy');
        $amount = number_format((float) $voucher->amount, 0, '.', ' ').' FCFA';
        $body = "{$voucher->requestedBy?->name} requests {$amount} for {$voucher->payee_name}: {$voucher->purpose}";

        if (! $voucher->needsStakeholders()) {
            $this->cashNotifier->toHoldersOf('vouchers.approve', 'voucher.requested', 'info', "Voucher {$voucher->number} to approve", $body, "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);

            return;
        }

        foreach (User::query()->whereIn('id', $voucher->requiredApproverIds())->get() as $approver) {
            $this->notifications->broadcastToUser($approver, 'voucher.stakeholder_approval', 'warning', "Your approval is needed: {$voucher->number}", $body, "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);

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
        $this->notifyRequester($voucher, 'voucher.approved', 'info', 'Voucher approved', "{$voucher->number} is approved — the cashier can pay it.");
        $this->cashNotifier->toHoldersOf('vouchers.pay', 'voucher.approved', 'info', "Voucher {$voucher->number} ready to pay", "{$voucher->payee_name}: ".number_format((float) $voucher->amount, 0, '.', ' ').' FCFA', "/vouchers/{$voucher->uuid}", 'voucher', $voucher->uuid);
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
