<?php

namespace Database\Factories;

use App\Models\Expenditure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expenditure>
 */
class ExpenditureFactory extends Factory
{
    protected $model = Expenditure::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => ExpenseCategoryFactory::new(),
            'user_id' => UserFactory::new(),
            'amount' => fake()->randomElement([1500, 3000, 5000, 10000, 25000, 50000]),
            'description' => fake()->sentence(6),
            'receipt_path' => null,
            'spent_at' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'notes' => null,
            'recorded_offline' => false,
            'recorded_by_device' => null,
        ];
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes): array => [
            'recorded_offline' => true,
            'recorded_by_device' => fake()->uuid(),
        ]);
    }
}
