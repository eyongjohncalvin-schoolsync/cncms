<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => ZoneFactory::new(),
            'user_id' => UserFactory::new(),
            'name' => fake()->name(),
            'location' => fake()->streetAddress(),
            'phone' => '6'.fake()->numerify('########'),
            'salary' => fake()->randomElement([30000, 40000, 50000, 60000, 75000]),
            'email' => fake()->optional(0.5)->safeEmail(),
            'dob' => fake()->dateTimeBetween('-45 years', '-20 years')->format('Y-m-d'),
            'marital_status' => fake()->randomElement(['yes', 'no']),
            'children' => fake()->numberBetween(0, 5),
            'status' => 'active',
            'picture' => null,
            'sync_token' => null,
            'last_sync_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'inactive',
        ]);
    }
}
