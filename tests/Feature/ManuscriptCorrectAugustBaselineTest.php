<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Manuscript;
use App\Models\Tenant;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * `manuscript:correct-august-baseline` — sets the imported 2026-08 baseline's
 * "Run at" to v1's real 2026-07-22 run and clears two phantom prepaid
 * counters. Disposable tenant (the command owns its own tenancy lifecycle).
 */
class ManuscriptCorrectAugustBaselineTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionDisposableTenant('cabl');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->tenant->delete();
        parent::tearDown();
    }

    private function withinTenancy(callable $cb): mixed
    {
        tenancy()->initialize($this->tenant);
        try {
            return $cb();
        } finally {
            tenancy()->end();
        }
    }

    public function test_apply_backdates_the_run_and_leaves_edited_rows_alone(): void
    {
        [$runId, $untouchedId, $editedId] = $this->withinTenancy(function (): array {
            $run = CommandRun::create([
                'command' => 'manuscript:calculate', 'period' => '2026-08',
                'ran_at' => Carbon::parse('2026-08-28 20:44:33'), 'status' => 'published',
                'published_at' => Carbon::parse('2026-08-28 20:44:33'), 'metadata' => ['synthetic' => true],
            ]);

            $zone = ZoneFactory::new()->create();
            $a = CustomerFactory::new()->create(['zone_id' => $zone->id]);
            $b = CustomerFactory::new()->create(['zone_id' => $zone->id]);

            $untouched = ManuscriptFactory::new()->create(['customer_id' => $a->id, 'period' => '2026-08']);
            DB::table('manuscripts')->where('id', $untouched->id)
                ->update(['created_at' => '2026-08-28 09:09:40', 'updated_at' => '2026-08-28 09:09:40']);

            $edited = ManuscriptFactory::new()->create(['customer_id' => $b->id, 'period' => '2026-08']);
            DB::table('manuscripts')->where('id', $edited->id)
                ->update(['created_at' => '2026-08-28 09:09:40', 'updated_at' => '2026-08-30 12:00:00']);

            return [$run->id, $untouched->id, $edited->id];
        });

        $this->artisan('manuscript:correct-august-baseline', ['--tenant' => $this->tenant->id, '--force' => true, '--apply' => true])
            ->assertExitCode(0);

        $this->withinTenancy(function () use ($runId, $untouchedId, $editedId): void {
            $this->assertSame('2026-07-22', CommandRun::find($runId)->ran_at->toDateString());

            $untouched = Manuscript::find($untouchedId);
            $this->assertSame('2026-07-22', $untouched->created_at->toDateString());
            $this->assertSame('2026-07-22', $untouched->updated_at->toDateString());

            $edited = Manuscript::find($editedId);
            $this->assertSame('2026-07-22', $edited->created_at->toDateString());
            $this->assertSame('2026-08-30', $edited->updated_at->toDateString(), 'a row edited after the import keeps its real edit time');
        });
    }

    public function test_dry_run_writes_nothing(): void
    {
        $runId = $this->withinTenancy(fn () => CommandRun::create([
            'command' => 'manuscript:calculate', 'period' => '2026-08',
            'ran_at' => Carbon::parse('2026-08-28 20:44:33'), 'status' => 'published',
        ])->id);

        $this->artisan('manuscript:correct-august-baseline', ['--tenant' => $this->tenant->id, '--force' => true])
            ->assertExitCode(0);

        $this->withinTenancy(fn () => $this->assertSame('2026-08-28', CommandRun::find($runId)->ran_at->toDateString()));
    }
}
