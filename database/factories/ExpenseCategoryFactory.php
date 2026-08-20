<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$name, $icon] = fake()->randomElement([
            ['Staff & Labour', 'ti-users'],
            ['Field Operations', 'ti-truck'],
            ['Network Maintenance', 'ti-tool'],
            ['Office & Admin', 'ti-building'],
            ['Utilities', 'ti-bolt'],
            ['MOMO Transaction Fees', 'ti-credit-card'],
            ['Broadcaster / Signal Fees', 'ti-antenna'],
            ['Equipment Purchase', 'ti-device-tv'],
            ['Miscellaneous', 'ti-dots'],
        ]);

        return [
            'name' => $name,
            'icon' => $icon,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 9),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
