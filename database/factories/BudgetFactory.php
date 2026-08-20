<?php

namespace Database\Factories;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => ExpenseCategoryFactory::new(),
            'period' => now()->format('Y-m'),
            'amount' => fake()->randomElement([20000, 50000, 100000, 150000, 200000]),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => $period,
        ]);
    }
}
