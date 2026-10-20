<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\FieldDevice;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\User;
use App\Support\ReceiptSecurityCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a receipt a collector says they handed over in the field before
 * the payment is stored (docs/plans/cash-controls.md, wave 2). Every
 * refusal is a ValidationException on `receipt_number` / `security_code`,
 * so the web form shows it inline and an offline sync item fails alone.
 *
 * What makes a field receipt acceptable:
 *   - the number was issued by the system, to THIS collector, and has not
 *     been used or cancelled (locked FOR UPDATE — two syncs can't both take
 *     the same number);
 *   - digital: the code matches what the holder's registered, un-revoked
 *     phone would sign for this number, customer, amount and time. A
 *     mismatch means the amount, customer or number was changed after the
 *     receipt was printed — or the receipt was never printed by our app;
 *   - book: no code (the leaf is handwritten); the number and holder are
 *     what is checked, and the customer can compare amounts on the check
 *     page.
 */
class FieldReceiptService
{
    /** How far in the future a phone's clock may run before the time is refused. */
    private const int CLOCK_SKEW_MINUTES = 15;

    /**
     * @return array{leaf: ReceiptLeaf, device: ?FieldDevice, issued_at: CarbonInterface}
     */
    public function claim(User $collector, Customer $customer, string $amount, string $receiptNumber, ?string $securityCode, ?string $issuedAt): array
    {
        $leaf = ReceiptLeaf::query()
            ->with('block.fieldDevice')
            ->where('number', strtoupper(trim($receiptNumber)))
            ->lockForUpdate()
            ->first();

        if ($leaf === null) {
            $this->refuse('receipt_number', "Receipt {$receiptNumber} was not issued by this system.");
        }

        $block = $leaf->block;

        if ($block->holder_user_id !== $collector->id) {
            $this->refuse('receipt_number', "Receipt {$leaf->number} belongs to another collector's stock.");
        }

        if (! $leaf->isUnused()) {
            $this->refuse('receipt_number', "Receipt {$leaf->number} is already {$leaf->status}.");
        }

        $issuedAtTime = $this->issuedAt($issuedAt, $block);

        if ($block->kind === ReceiptBlock::KIND_BOOK) {
            return ['leaf' => $leaf, 'device' => null, 'issued_at' => $issuedAtTime];
        }

        $device = $block->fieldDevice;

        if ($device === null || $device->isRevoked()) {
            $this->refuse('receipt_number', "Receipt {$leaf->number} was issued to a phone that has been revoked.");
        }

        if ($securityCode === null || $securityCode === '') {
            $this->refuse('security_code', "Enter the security code printed on receipt {$leaf->number}.");
        }

        $expected = ReceiptSecurityCode::compute(
            ReceiptSecurityCode::deviceKey($device->secret),
            ReceiptSecurityCode::canonical($leaf->number, $customer->uuid, $amount, $issuedAtTime->getTimestamp()),
        );

        if (! ReceiptSecurityCode::matches($expected, $securityCode)) {
            $this->refuse('security_code', "The security code on receipt {$leaf->number} does not match its customer, amount and time — it may have been altered.");
        }

        return ['leaf' => $leaf, 'device' => $device, 'issued_at' => $issuedAtTime];
    }

    private function issuedAt(?string $issuedAt, ReceiptBlock $block): CarbonInterface
    {
        if ($issuedAt === null || $issuedAt === '') {
            if ($block->kind === ReceiptBlock::KIND_DIGITAL) {
                $this->refuse('receipt_issued_at', 'A digital receipt must carry the time it was printed.');
            }

            return CarbonImmutable::now();
        }

        try {
            $time = CarbonImmutable::parse($issuedAt);
        } catch (Throwable) {
            $this->refuse('receipt_issued_at', 'The receipt time is not a valid date.');
        }

        if ($time->isAfter(now()->addMinutes(self::CLOCK_SKEW_MINUTES)) || $time->isBefore($block->created_at->copy()->subDay())) {
            $this->refuse('receipt_issued_at', 'The receipt time is outside the period this number could have been used.');
        }

        return $time;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
