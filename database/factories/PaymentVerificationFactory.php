<?php

namespace Database\Factories;

use App\Models\PaymentVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentVerification>
 */
class PaymentVerificationFactory extends Factory
{
    protected $model = PaymentVerification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => PaymentFactory::new(),
            'receipt_photo_path' => null,
            'momo_ref' => null,
            'momo_status' => null,
            'verified_by' => null,
            'verified_at' => null,
            'status' => 'pending',
            'notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
            'verified_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'rejected',
            'verified_at' => now(),
        ]);
    }
}
