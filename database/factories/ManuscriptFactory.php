<?php

namespace Database\Factories;

use App\Models\Manuscript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Manuscript>
 */
class ManuscriptFactory extends Factory
{
    protected $model = Manuscript::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bill = fake()->randomElement([2000, 2500, 3000]);

        return [
            'customer_id' => CustomerFactory::new(),
            'bill' => $bill,
            'total_arrears' => 0,
            'credit' => 0,
            'total_bill' => $bill,
            'payment_expiration' => null,
            'period' => now()->format('Y-m'),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => $period,
        ]);
    }

    public function withArrears(float $arrears): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_arrears' => $arrears,
            'total_bill' => ($attributes['bill'] ?? 0) + $arrears,
        ]);
    }
}
