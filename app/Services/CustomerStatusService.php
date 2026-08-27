<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Company;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fast, purpose-built status transitions for customers — disconnect,
 * suspend, reconnect — distinct from the generic customer-edit form
 * (App\Http\Controllers\CustomerController::update()), the same way
 * PaymentController::verify() is a dedicated action separate from a
 * generic payment edit. See business-rules.md section 1 (customer
 * lifecycle) and section 6 (reconnection fine).
 *
 * Bulk-first by design: office staff typically select several customers at
 * once (App\Http\Controllers\DisconnectionsController's bulk actions), so
 * *Many() is the primary API here and mirrors
 * App\Services\PaymentVerificationService::verifyMany()'s shape exactly —
 * each customer id is processed independently in its own attempt, a bad row
 * is skipped rather than failing the whole batch, and the result is
 * {succeeded: string[], skipped: array<uuid, reason>}. The singular
 * disconnect()/suspend()/reconnect() methods (used by the single-customer
 * quick actions on Customers/Show.tsx and Customers/Index.tsx, and by any
 * future single-customer caller such as an arrears-based disconnect-
 * eligibility monitor) are thin wrappers that let a validation failure
 * bubble up as a normal ValidationException instead of being swallowed into
 * a skip entry.
 */
class CustomerStatusService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Disconnects one customer, throwing on failure. See disconnectMany()
     * for the bulk, skip-on-failure equivalent.
     */
    public function disconnect(Customer $customer, ?string $note = null): Customer
    {
        return $this->disconnectOne($customer, $note);
    }

    /**
     * @param  string  $reason  One of: tv_problem, poor_service, customer_request, zone_transfer, other.
     * @param  bool  $pausePrepaid  See prepaid-pause-handling.md section 2/5 — the admin's choice at
     *                              suspend time, defaulting to the recommended "pause" option. Only
     *                              actually recorded (as `customers.prepaid_paused`) when the customer
     *                              has an active/unexpired prepaid window; otherwise it's moot and
     *                              ignored, matching the UI's "skip the choice entirely" behavior when
     *                              there's nothing to choose between.
     */
    public function suspend(Customer $customer, string $reason, ?string $note = null, bool $pausePrepaid = true): Customer
    {
        return $this->suspendOne($customer, $reason, $note, $pausePrepaid);
    }

    public function reconnect(Customer $customer, ?string $note = null, bool $includeFine = false, ?string $arrearsPayment = null): Customer
    {
        return $this->reconnectOne($customer, $note, $includeFine, $arrearsPayment);
    }

    /**
     * Disconnects many customers in one pass — the primary bulk workflow on
     * the Disconnections page. `$note`, if given, is shared across the
     * whole batch (business-rules.md section 1: reason is implicitly
     * "non-payment" either way).
     *
     * @param  string[]  $customerUuids
     * @return array{succeeded: string[], skipped: array<string, string>}
     */
    public function disconnectMany(array $customerUuids, ?string $note = null): array
    {
        return $this->applyToMany($customerUuids, fn (Customer $customer) => $this->disconnectOne($customer, $note));
    }

    /**
     * Suspends many customers in one pass with a single shared reason/note
     * applied to the whole batch.
     *
     * @param  string[]  $customerUuids
     * @param  string  $reason  One of: tv_problem, poor_service, customer_request, zone_transfer, other.
     * @return array{succeeded: string[], skipped: array<string, string>}
     */
    public function suspendMany(array $customerUuids, string $reason, ?string $note = null): array
    {
        return $this->applyToMany($customerUuids, fn (Customer $customer) => $this->suspendOne($customer, $reason, $note));
    }

    /**
     * Reconnects many customers in one pass. `$includeFine` is a single
     * opt-in toggle covering the whole batch (2026-08 owner decision — see
     * reconnectOne()'s doc comment): unchecked/false by default, applied
     * uniformly to every selected customer regardless of whether they were
     * `disconnected` or `suspended`, exactly like a batch that's all one
     * status or the other.
     *
     * @param  string[]  $customerUuids
     * @return array{succeeded: string[], skipped: array<string, string>}
     */
    public function reconnectMany(array $customerUuids, ?string $note = null, bool $includeFine = false): array
    {
        return $this->applyToMany($customerUuids, fn (Customer $customer) => $this->reconnectOne($customer, $note, $includeFine));
    }

    /**
     * Runs $action once per customer uuid, each attempt isolated from the
     * others — one missing customer or one failed transition guard doesn't
     * abort the rest of the batch. Mirrors
     * PaymentVerificationService::verifyMany()'s loop-and-collect shape.
     *
     * @param  string[]  $customerUuids
     * @return array{succeeded: string[], skipped: array<string, string>}
     */
    private function applyToMany(array $customerUuids, callable $action): array
    {
        $succeeded = [];
        $skipped = [];

        foreach ($customerUuids as $uuid) {
            $customer = $this->customers->findByUuid($uuid);

            if (! $customer) {
                $skipped[$uuid] = 'Customer not found.';

                continue;
            }

            try {
                $action($customer);
                $succeeded[] = $uuid;
            } catch (ValidationException $e) {
                $skipped[$uuid] = collect($e->errors())->flatten()->implode(' ');
            }
        }

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    private function disconnectOne(Customer $customer, ?string $note): Customer
    {
        $this->guardTransition($customer, 'disconnected', ['active', 'passive', 'suspended']);

        $customer = $this->customers->updateStatus($customer, [
            'status' => 'disconnected',
            'status_reason' => 'non_payment',
            'status_note' => $note,
            // prepaid-pause-handling.md section 2: disconnect's eventual
            // extension is unconditional, so it never reads
            // `prepaid_paused` — cleared here so a stale value from an
            // earlier suspend cycle (e.g. suspended -> disconnected
            // directly, without an intervening reconnect) can't linger.
            'status_changed_at' => Carbon::now(),
            'prepaid_paused' => null,
        ]);

        $this->forgetCache($customer);

        return $customer;
    }

    private function suspendOne(Customer $customer, string $reason, ?string $note, bool $pausePrepaid = true): Customer
    {
        $this->guardTransition($customer, 'suspended', ['active', 'passive', 'disconnected']);

        // prepaid-pause-handling.md section 3: only recorded when there's an
        // active/unexpired prepaid window to actually pause — otherwise the
        // admin's choice (or this default) was moot, and `prepaid_paused`
        // stays null exactly like today's plain status change.
        $hasActivePrepaidWindow = $this->hasActiveUnexpiredPaymentExpiration($customer);

        $customer = $this->customers->updateStatus($customer, [
            'status' => 'suspended',
            'status_reason' => $reason,
            'status_note' => $note,
            'status_changed_at' => Carbon::now(),
            'prepaid_paused' => $hasActivePrepaidWindow ? $pausePrepaid : null,
        ]);

        $this->forgetCache($customer);

        return $customer;
    }

    /**
     * business-rules.md section 6 (as of the 2026-08 owner decision below)
     * makes the reconnection fine an explicit, admin-discretion opt-in —
     * NOT an automatic or mandatory charge — via `$includeFine`, uniformly
     * for reconnection from EITHER `disconnected` or `suspended`. The fine
     * amount is admin-configurable (Settings > Company Info,
     * `companies.reconnection_fine`, defaulting to 2,000 FCFA — see the
     * migration) rather than fixed in code, so it is read fresh from
     * Company::cached() on every reconnection instead of a class constant.
     * `manuscripts` rows are owned exclusively by the manuscript:calculate
     * command (see ManuscriptService::forgetSummaryCache()'s doc comment —
     * "that command is the only writer of `manuscripts` rows"), so this
     * deliberately does NOT reach into the customer's latest manuscript to
     * bump total_arrears directly. Instead it goes through the normal
     * PaymentService::create() path: a verified payment for the configured
     * fine amount is recorded (auto-verified here since only
     * super/admin/manager can even reach this action — see
     * CustomerPolicy::reconnect()/bulkReconnect()), and the next
     * manuscript:calculate run folds it into the ledger as ordinary
     * verified income, exactly like any other payment. No float math is
     * involved: the fine is read as a decimal string, never derived via
     * native arithmetic.
     *
     * $arrearsPayment (single-customer reconnectOne() only — reconnectMany()
     * never passes one, it stays fine-only per the product owner's explicit
     * "only one-at-a-time" scoping decision) is an OPTIONAL second, separate
     * Payment recording a full or partial payment against the customer's
     * outstanding arrears at the moment of reconnection. It is recorded via
     * the exact same PaymentService::create() bypass as the fine payment
     * above — never through StorePaymentRequest/the normal Record Payment
     * form — so it is unaffected by that form's disconnected/suspended
     * customer block (see StorePaymentRequest's doc comment). The amount is
     * NOT validated against the customer's actual arrears figure here: the
     * admin has full discretion to record a partial, full, or (deliberately
     * or not) an over-arrears payment, and the UI's "balance remaining"
     * figure is guidance only, not an enforced constraint. All comparisons
     * use bccomp() string math, never float operators, matching
     * ManuscriptCalculator's convention.
     *
     * History: this used to be mandatory-and-automatic for `disconnected`
     * only (a `$fineCollected` confirmation checkbox, required — `accepted`
     * validation rule — whenever the customer being reconnected was
     * currently `disconnected`, never for `suspended`). A 2026-08 audit
     * first asked whether that disconnected-only scoping should extend to
     * `suspended` too, once ManuscriptCalculator started freezing
     * `suspended` arrears the same way it already froze `disconnected`
     * arrears — freezing is a billing-correctness fix that applies
     * regardless of *why* service was paused, so it was extended to both
     * statuses; but disconnected-only felt right for the fine specifically
     * since `disconnectOne()` hardcodes `status_reason` to `non_payment`
     * while `suspendOne()`'s reasons never are. The owner then overrode that
     * distinction entirely: the fine is no longer automatic for ANY status —
     * it's the office's discretionary call every time, made explicit via an
     * "Include reconnection fine" checkbox (unchecked by default) in the UI,
     * wired straight through to $includeFine here. `disconnected` vs
     * `suspended` therefore no longer differ on the fine at all — they only
     * still differ on the freeze/payment_expiration-carry-forward mechanics
     * ManuscriptCalculator handles, which is a completely separate concern
     * from this method.
     *
     * Also applies prepaid-pause-handling.md's extension: if the customer
     * being reconnected has a `payment_expiration` and is eligible per
     * section 4 (unconditionally for `disconnected`; only if
     * `prepaid_paused` was true for `suspended`), it's pushed forward by
     * exactly how long the freeze lasted (`now() - status_changed_at`)
     * before the status flips to 'active' — see extendPrepaidWindow().
     *
     * @throws ValidationException if the customer is not currently
     *                              disconnected or suspended.
     */
    private function reconnectOne(Customer $customer, ?string $note, bool $includeFine, ?string $arrearsPayment = null): Customer
    {
        $this->guardTransition($customer, 'active', ['disconnected', 'suspended']);

        $fine = $this->reconnectionFine();
        $hasArrearsPayment = $arrearsPayment !== null && bccomp($arrearsPayment, '0.00', 2) > 0;

        // Captured BEFORE updateStatus() below overwrites status/status_changed_at/
        // prepaid_paused — prepaid-pause-handling.md section 4's rule is
        // evaluated against the state as it was WHILE frozen, not after.
        $previousStatus = $customer->status;
        $frozenSince = $customer->status_changed_at;
        $shouldExtendPrepaid = $previousStatus === 'disconnected'
            || ($previousStatus === 'suspended' && $customer->prepaid_paused === true);

        return DB::transaction(function () use ($customer, $note, $includeFine, $fine, $hasArrearsPayment, $arrearsPayment, $shouldExtendPrepaid, $frozenSince): Customer {
            if ($includeFine) {
                $this->payments->create(new PaymentData(
                    customerUuid: $customer->uuid,
                    amount: $fine,
                    frequency: 'monthly',
                ));
            }

            if ($hasArrearsPayment) {
                $this->payments->create(new PaymentData(
                    customerUuid: $customer->uuid,
                    amount: $arrearsPayment,
                    frequency: 'monthly',
                ));
            }

            if ($shouldExtendPrepaid) {
                $this->extendPrepaidWindow($customer, $frozenSince);
            }

            $customer = $this->customers->updateStatus($customer, [
                'status' => 'active',
                'status_reason' => 'reconnected',
                'status_note' => $note,
                'status_changed_at' => Carbon::now(),
                // One-suspension-cycle flag — always cleared on reconnect
                // regardless of outcome (prepaid-pause-handling.md section 3).
                'prepaid_paused' => null,
            ]);

            $this->forgetCache($customer);

            return $customer;
        });
    }

    private function guardTransition(Customer $customer, string $target, array $allowedFrom): void
    {
        if (! in_array($customer->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => ["{$customer->name} cannot be moved to '{$target}' from its current status ('{$customer->status}')."],
            ]);
        }
    }

    /**
     * Whether $customer currently has an active, unexpired prepaid window —
     * i.e. `payment_expiration` is set AND still in the future. Read from
     * their latest manuscript row (`manuscripts.payment_expiration` — there
     * is no such column on `customers` itself; see
     * App\Services\ManuscriptCalculator's class doc for how that field is
     * produced and carried forward). Backs prepaid-pause-handling.md
     * section 3's "only ask/record when it's actually relevant" rule at
     * suspend time. A customer with no manuscript yet, or whose
     * `payment_expiration` has already lapsed, has nothing active to pause.
     */
    private function hasActiveUnexpiredPaymentExpiration(Customer $customer): bool
    {
        $expiration = $customer->latestManuscript?->payment_expiration;

        return $expiration !== null && Carbon::parse($expiration)->isFuture();
    }

    /**
     * prepaid-pause-handling.md section 4's core mechanic: extends the
     * customer's current prepaid window by exactly how long the freeze that
     * just ended actually lasted, so the unused portion of already-paid-for
     * time is honored on reconnection instead of silently forfeited.
     *
     * Deliberately does NOT go through ManuscriptCalculator/manuscript:
     * calculate — this is a narrow, single-column write directly onto the
     * customer's latest manuscript row, not a re-run of the arrears/credit
     * ledger formula (see that class's doc comment: "manuscripts rows are
     * owned exclusively by the manuscript:calculate command" refers to the
     * BILLING attributes it computes, not this date field). It's safe
     * precisely because it doesn't touch total_arrears/credit/total_bill or
     * any payment's processed_at/processed_period stamp: the next
     * manuscript:calculate run simply reads the now-extended
     * `payment_expiration` forward as `$previousExpiration`, exactly as it
     * already does for any other period-to-period carry-forward.
     *
     * A no-op when there's nothing to extend (no manuscript yet, or
     * `payment_expiration` was never set) or when $frozenSince is null — a
     * freeze that began before this feature shipped has no `status_changed_at`
     * anchor to compute a real duration from, and per prepaid-pause-
     * handling.md section 6 this deliberately does NOT retroactively guess
     * or fabricate one.
     */
    private function extendPrepaidWindow(Customer $customer, ?Carbon $frozenSince): void
    {
        if ($frozenSince === null) {
            return;
        }

        $manuscript = $customer->latestManuscript;

        if ($manuscript === null || $manuscript->payment_expiration === null) {
            return;
        }

        // Calendar-aware duration (not a fixed day count) — a freeze
        // spanning exactly 3 calendar months extends the expiration by 3
        // months, not by a hardcoded ~90 days, matching section 4's
        // "3-year freeze extends by 3 years, 3-week one by 3 weeks" example.
        $frozenDuration = $frozenSince->diffAsCarbonInterval(Carbon::now());
        $extended = Carbon::parse($manuscript->payment_expiration)->add($frozenDuration);

        $manuscript->update(['payment_expiration' => $extended]);
    }

    private function forgetCache(Customer $customer): void
    {
        Cache::forget("customers:show:{$customer->uuid}");
    }

    /**
     * Admin-configurable reconnection fine (Settings > Company Info),
     * defaulting to '2000.00' only if the single Company settings row is
     * somehow missing — the migration's column default already covers the
     * normal case, this is just defense against an unseeded tenant.
     */
    private function reconnectionFine(): string
    {
        return (string) (Company::cached()?->reconnection_fine ?? '2000.00');
    }
}
