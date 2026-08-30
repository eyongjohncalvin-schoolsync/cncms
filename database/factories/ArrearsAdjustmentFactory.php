<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ArrearsAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrearsAdjustment>
 *
 * Mirrors ComplaintFactory's shape exactly, including the same
 * requested_by-is-not-mass-assignable trap: `requested_by` is deliberately
 * absent from ArrearsAdjustment's #[Fillable] list (see that model's class
 * doc), so it is NOT part of definition() — callers must use the
 * requestedBy() state below, e.g.
 * ArrearsAdjustmentFactory::new()->requestedBy($userId)->create().
 */
class ArrearsAdjustmentFactory extends Factory
{
    protected $model = ArrearsAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'target_period' => now()->format('Y-m'),
            'direction' => 'decrease',
            'target' => 'arrears',
            'amount' => '5000.00',
            'reason_category' => 'billing_error',
            'reason_note' => fake()->sentence(),
            'arrears_snapshot' => '0.00',
            'status' => 'pending',
        ];
    }

    /**
     * Sets requested_by via direct attribute assignment in an afterMaking
     * hook, bypassing mass assignment — see this class's doc comment.
     */
    public function requestedBy(int $userId): static
    {
        return $this->afterMaking(function (ArrearsAdjustment $adjustment) use ($userId): void {
            $adjustment->requested_by = $userId;
        });
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_period' => $period,
        ]);
    }

    public function withAmount(string $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => $amount,
        ]);
    }

    public function increase(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => 'increase',
        ]);
    }

    public function withArrearsSnapshot(string $snapshot): static
    {
        return $this->state(fn (array $attributes): array => [
            'arrears_snapshot' => $snapshot,
        ]);
    }

    /**
     * A credit-target correction (2026-08-30) — touches manuscripts.credit
     * rather than total_arrears. Defaults direction to 'increase' (claw
     * back) and reason_category to a credit-specific one; callers override
     * as needed.
     */
    public function creditTarget(): static
    {
        return $this->state(fn (array $attributes): array => [
            'target' => 'credit',
            'direction' => 'increase',
            'reason_category' => 'credit_correction',
        ]);
    }

    public function withCreditSnapshot(string $snapshot): static
    {
        return $this->state(fn (array $attributes): array => [
            'credit_snapshot' => $snapshot,
        ]);
    }

    public function reasonCategory(string $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason_category' => $category,
        ]);
    }

    public function pendingSecondApproval(int $approvedBy): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending_second_approval',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);
    }

    public function approved(int $approvedBy, ?int $secondApprovedBy = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'second_approved_by' => $secondApprovedBy,
            'second_approved_at' => $secondApprovedBy !== null ? now() : null,
        ]);
    }

    public function rejected(string $reason = 'Not warranted.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }
}
