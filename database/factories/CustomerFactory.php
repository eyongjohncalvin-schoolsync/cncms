<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => ZoneFactory::new(),
            'name' => fake()->name(),
            'location' => fake()->streetName(),
            // Bill rate distribution roughly mirrors production: mostly 2,500
            // (standard) and 3,000 (premium), a smaller 2,000 (economy) tier,
            // and a handful of higher VIP/Operator packages.
            'bill' => fake()->randomElement([
                2000, 2000,
                2500, 2500, 2500, 2500, 2500, 2500,
                3000, 3000, 3000,
                4000, 5000, 7500,
            ]),
            'others' => 0,
            'phone' => fake()->boolean(22) ? '6'.fake()->numerify('########') : null,
            'description' => null,
            'level' => fake()->randomElement(['normal', 'normal', 'normal', 'normal', 'Vip', 'Operator']),
            'status' => fake()->randomElement(['active', 'active', 'active', 'active', 'disconnected']),
        ];
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'disconnected',
        ]);
    }

    public function passive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'passive',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'active',
        ]);
    }

    public function withSeedBalance(float $others): static
    {
        return $this->state(fn (array $attributes): array => [
            'others' => $others,
        ]);
    }
}
