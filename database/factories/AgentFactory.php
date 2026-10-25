<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\Zone;
use App\Models\ZoneAgent;
use App\Services\ZoneCoverageService;
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

    /**
     * Like a real new agent (AgentService::create()): they cover their home
     * zone — its responsible collector when it has none yet, else a helper.
     * withoutCoverage() skips it, for an agent who covers nothing.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Agent $agent): void {
            app(ZoneCoverageService::class)->coverOnly($agent, Zone::query()->withoutGlobalScope(BranchScope::class)->findOrFail($agent->zone_id), null);
        });
    }

    public function withoutCoverage(): static
    {
        return $this->afterCreating(function (Agent $agent): void {
            ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->delete();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'inactive',
        ]);
    }
}
