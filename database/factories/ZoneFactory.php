<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Reuses the tenant's existing (real) "Main Branch" row rather
            // than spawning a new Branch per factory call — the real
            // tenant schema always has exactly one branch seeded by
            // 2026_08_24_160000_create_branches_table.php, and tests that
            // exercise the "only one branch exists" default (see
            // ZoneService::resolveBranchId) depend on that staying true.
            // BranchFactory is still used as a fallback for the unlikely
            // case no branch exists yet.
            'branch_id' => fn () => Branch::query()->value('id') ?? BranchFactory::new()->create()->id,
            'name' => 'Z'.fake()->unique()->numerify('##').' ('.substr(fake()->lastName(), 0, 15).')',
            'town' => 'KUMBA 3',
        ];
    }
}
