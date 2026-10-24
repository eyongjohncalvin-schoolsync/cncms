<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\OneOffChargeData;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relocation and re-installation fees (docs/plans/installation-and-promotions.md,
 * "Out of scope / later": the installation charge model with another kind).
 *
 *  - relocate()  — the customer moved house: same account and history, a
 *                  new location (and zone, through the same branch-fenced
 *                  zone lookup as a customer edit), and a relocation fee.
 *  - reinstall() — installed again at the same address: a re-installation fee.
 *
 * The fee defaults to the company's (companies.relocation_fee /
 * reinstallation_fee). A lower fee or a full waiver needs a reason and
 * admin/owner approval, maker ≠ checker — exactly the installation rules
 * (D8). The charge, its first payment (with receipt) and the move are one
 * transaction; everything after that — instalments, waiver and fee
 * decisions, refunds by bon — is App\Services\CustomerChargeService, the
 * same as an installation. No free months come with either.
 *
 * The money is payments.purpose = 'installation', so billing, arrears,
 * credit and disconnection eligibility never see it (D1), and the balance
 * prints on the bill as its own line.
 */
class CustomerRelocationService
{
    public function __construct(
        private readonly CustomerChargeService $charges,
        private readonly CustomerService $customers,
    ) {}

    /**
     * @return array{charge: CustomerCharge, payment: ?Payment, customer: Customer}
     */
    public function relocate(Customer $customer, OneOffChargeData $data, User $actor): array
    {
        if ($data->toLocation === null) {
            throw ValidationException::withMessages(['location' => 'Enter the new address.']);
        }

        return DB::transaction(function () use ($customer, $data, $actor): array {
            $fromZoneId = $customer->zone_id;
            $fromLocation = $customer->location;
            $toZoneId = $data->toZoneUuid !== null ? $this->customers->resolveZoneId($data->toZoneUuid) : $fromZoneId;

            $result = $this->openWithPayment($customer, CustomerCharge::KIND_RELOCATION, $data, $actor, [
                'from_zone_id' => $fromZoneId,
                'to_zone_id' => $toZoneId,
                'from_location' => $fromLocation,
                'to_location' => $data->toLocation,
            ]);

            $moved = $this->customers->update($customer, new CustomerData(
                zoneUuid: $toZoneId !== $fromZoneId ? $data->toZoneUuid : null,
                location: $data->toLocation,
            ));

            return [...$result, 'customer' => $moved];
        });
    }

    /**
     * @return array{charge: CustomerCharge, payment: ?Payment, customer: Customer}
     */
    public function reinstall(Customer $customer, OneOffChargeData $data, User $actor): array
    {
        return DB::transaction(fn (): array => [
            ...$this->openWithPayment($customer, CustomerCharge::KIND_REINSTALLATION, $data, $actor, []),
            'customer' => $customer,
        ]);
    }

    /**
     * The default fee for a kind — the branch's own when it sets one
     * (branches v2 D4), else headquarters'; null when none is set.
     */
    public static function defaultFee(string $kind, ?int $branchId = null): ?string
    {
        $company = Company::forBranch($branchId);
        $fee = match ($kind) {
            CustomerCharge::KIND_RELOCATION => $company?->relocation_fee,
            CustomerCharge::KIND_REINSTALLATION => $company?->reinstallation_fee,
            default => null,
        };

        return $fee !== null && bccomp((string) $fee, '0', 2) > 0 ? bcadd((string) $fee, '0', 2) : null;
    }

    /**
     * Opens the charge (with the D8 approval / waiver request when the fee
     * is below the default) and records what was paid now.
     *
     * @param  array<string, mixed>  $extra
     * @return array{charge: CustomerCharge, payment: ?Payment}
     */
    private function openWithPayment(Customer $customer, string $kind, OneOffChargeData $data, User $actor, array $extra): array
    {
        $label = strtolower(CustomerCharge::KIND_LABELS[$kind]);
        $standard = self::defaultFee($kind, $customer->branch_id);
        $fee = $data->fee ?? $standard;

        if ($fee === null || (bccomp($fee, '0', 2) <= 0 && $standard === null)) {
            throw ValidationException::withMessages(['fee' => "Enter the {$label} fee — no default fee is set."]);
        }

        $waiverNeeded = bccomp($fee, '0', 2) <= 0;
        $feeNeedsApproval = ! $waiverNeeded && $standard !== null && bccomp($fee, $standard, 2) < 0;
        $amount = $waiverNeeded ? (string) $standard : $fee;

        if (($waiverNeeded || $feeNeedsApproval) && $data->reason === null) {
            throw ValidationException::withMessages(['reason' => 'Say why the fee is '.($waiverNeeded ? 'waived' : 'lower than the standard '.number_format((float) $standard, 0, '.', ' ').' FCFA').' — an admin or the owner must approve it.']);
        }

        if ($waiverNeeded && bccomp($data->paidNow, '0', 2) > 0) {
            throw ValidationException::withMessages(['paid_now' => "A waived {$label} fee is not paid — set the amount paid to 0."]);
        }

        if (bccomp($data->paidNow, $amount, 2) > 0) {
            throw ValidationException::withMessages(['paid_now' => "More than the {$label} fee cannot be paid toward it."]);
        }

        $charge = $this->charges->open(
            $customer,
            $amount,
            $feeNeedsApproval ? $data->reason : null,
            $actor,
            $kind,
            [
                ...$extra,
                'note' => $data->note,
                'standard_amount' => $standard,
                'approval_status' => $feeNeedsApproval ? CustomerCharge::DECISION_PENDING : null,
            ],
        );

        if ($waiverNeeded) {
            $this->charges->requestWaiver($charge, (string) $data->reason, $actor);
        }

        $payment = bccomp($data->paidNow, '0', 2) > 0
            ? $this->charges->recordPayment($charge->fresh(), $data->paidNow, $data->method, $data->methodReference)
            : null;

        return ['charge' => $charge->fresh(), 'payment' => $payment];
    }
}
