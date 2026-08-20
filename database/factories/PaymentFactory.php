<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'amount' => fake()->randomElement([1000, 2000, 2500, 3000, 5000, 7500, 10000]),
            'credit' => 0,
            'frequency' => 'monthly',
            'expiration_date' => null,
            'months' => null,
            'verification_status' => 'verified',
            'processed_at' => null,
            'recorded_offline' => false,
            'recorded_by_device' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verification_status' => 'pending',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verification_status' => 'rejected',
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
        ]);
    }

    public function months(int $months, ?float $monthlyRate = null): static
    {
        return $this->state(function (array $attributes) use ($months, $monthlyRate): array {
            $rate = $monthlyRate ?? 2500;

            return [
                'amount' => $rate * $months,
                'frequency' => 'months',
                'months' => $months,
                'expiration_date' => now()->addMonths($months)->toDateString(),
            ];
        });
    }

    public function yearly(?float $monthlyRate = null): static
    {
        return $this->months(12, $monthlyRate)->state(fn (array $attributes): array => [
            'frequency' => 'yearly',
        ]);
    }
}
