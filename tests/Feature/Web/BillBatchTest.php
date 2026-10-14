<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Jobs\GenerateBulkBillsJob;
use App\Jobs\GenerateZoneBillsJob;
use App\Models\BillBatch;
use App\Models\BillBatchFile;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\BillBatchService;
use App\Services\ManuscriptService;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * Asynchronous (queued) bill generation — owner's 2026-08-30 ask
 * (App\Services\BillBatchService). Runs against the shared real tenant with
 * DatabaseTransactions (rolls back); every fixture is scoped to a fresh
 * ZoneFactory + zone_uuid filter so the ~283 real seeded customers never
 * perturb an assertion.
 *
 * The dispatch-side tests use Bus::fake() (no job actually runs, so no
 * tenancy re-init). The render tests call a job's handle() directly against
 * a Storage::fake('local') disk and assert a real `%PDF-` file landed.
 */
class BillBatchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use UsesDisposableTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Zone, 1: string}
     */
    private function seedZoneWithRecipients(array $names): array
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create(['name' => 'ZZ '.Str::random(6)]);

        foreach ($names as $name) {
            $customer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'name' => $name, 'bill' => 2500]);
            ManuscriptFactory::new()->forPeriod($period)->create([
                'customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500,
            ]);
        }

        return [$zone, $period];
    }

    public function test_generate_dispatches_a_batch_and_creates_a_bill_batch_row(): void
    {
        Bus::fake();
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $this->actingAsRole('manager');

        $response = $this->post('/manuscripts/bills/generate', ['period' => $period, 'zone_uuid' => $zone->uuid]);

        $response->assertRedirect(route('manuscripts.index', ['period' => $period]));
        $response->assertSessionHas('success');

        $batch = BillBatch::query()->where('period', $period)->latest('id')->first();
        $this->assertNotNull($batch);
        $this->assertSame('queued', $batch->status);
        $this->assertSame(2, $batch->total_bills);
        $this->assertSame(1, $batch->total_zones);

        Bus::assertBatched(fn ($pending): bool => str_starts_with((string) $pending->name, "bill_generation:{$period}:"));
    }

    public function test_generate_is_denied_to_a_worker(): void
    {
        $this->actingAsRole('worker');

        $this->post('/manuscripts/bills/generate', ['period' => Carbon::now()->format('Y-m')])->assertForbidden();
    }

    public function test_generate_flashes_an_error_when_no_active_recipient_exists(): void
    {
        Bus::fake();
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create(['name' => 'ZZ '.Str::random(6)]);
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        // Scoped to this test's own fixture, not `period` globally — the
        // shared tenant may already carry a real bill_batches row for the
        // current period.
        $before = BillBatch::query()->count();

        $this->actingAsRole('manager');

        $this->post('/manuscripts/bills/generate', ['period' => $period, 'zone_uuid' => $zone->uuid])
            ->assertRedirect()
            ->assertSessionHas('error');

        Bus::assertNothingBatched();
        $this->assertSame($before, BillBatch::query()->count());
    }

    /**
     * Paper saving (owner's ask): only active customers with something to
     * pay get a slip — not one whose credit covers the bill, whose prepaid
     * months cover it, or whose legacy expiry date is still ahead (all of
     * which the calculator records as total_bill = 0). A prepaid customer
     * still owing old arrears does get one.
     */
    public function test_generate_skips_customers_with_nothing_to_pay(): void
    {
        Bus::fake();
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create(['name' => 'ZZ '.Str::random(6)]);

        $customerWith = function (string $name, array $manuscript, string $status = 'active') use ($zone, $period): void {
            $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => $name, 'bill' => 2500, 'status' => $status]);
            ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'bill' => 2500, ...$manuscript]);
        };

        $customerWith('Owes', ['total_arrears' => 2500, 'credit' => 0, 'total_bill' => 5000]);
        $customerWith('Prepaid but owes old debt', ['total_arrears' => 1500, 'prepaid_months_remaining' => 2, 'prepaid_rate' => 2500, 'total_bill' => 1500]);
        $customerWith('Credit covers it', ['total_arrears' => 0, 'credit' => 4000, 'total_bill' => 0]);
        $customerWith('Prepaid months', ['total_arrears' => 0, 'credit' => 0, 'prepaid_months_remaining' => 3, 'prepaid_rate' => 2500, 'total_bill' => 0]);
        $customerWith('Expiry ahead', ['total_arrears' => 0, 'credit' => 0, 'payment_expiration' => now()->addMonths(2), 'total_bill' => 0]);
        $customerWith('Disconnected debtor', ['total_arrears' => 9000, 'total_bill' => 0], 'disconnected');

        $this->actingAsRole('manager');
        $this->post('/manuscripts/bills/generate', ['period' => $period, 'zone_uuid' => $zone->uuid])->assertSessionHas('success');

        $this->assertSame(2, BillBatch::query()->where('period', $period)->latest('id')->firstOrFail()->total_bills);

        $recipients = app(ManuscriptService::class)->billRecipients(['period' => $period, 'zone_uuid' => $zone->uuid])['customers'];
        $this->assertSame(['Owes', 'Prepaid but owes old debt'], $recipients->pluck('name')->all());
    }

    public function test_generate_explains_when_nobody_has_anything_to_pay(): void
    {
        Bus::fake();
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create(['name' => 'ZZ '.Str::random(6)]);
        $customer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'credit' => 9000, 'total_bill' => 0]);

        $this->actingAsRole('manager');

        $this->post('/manuscripts/bills/generate', ['period' => $period, 'zone_uuid' => $zone->uuid])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'anything to pay'));

        Bus::assertNothingBatched();
    }

    public function test_zone_job_writes_a_real_pdf_artifact(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $customerIds = $zone->customers()->orderBy('name')->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'queued', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 1,
        ]);

        (new GenerateZoneBillsJob($batch->id, $period, $zone->id, $zone->name, $customerIds))
            ->handle(app(BillBatchService::class));

        $file = BillBatchFile::query()->where('bill_batch_id', $batch->id)->where('kind', 'zone')->firstOrFail();
        $this->assertSame(2, $file->bill_count);
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($file->path));
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_bulk_job_writes_a_single_real_pdf(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben', 'Cara']);
        $customerIds = $zone->customers()->orderBy('name')->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'queued', 'density' => 1, 'template' => 'classic',
            'total_bills' => 3, 'total_zones' => 1,
        ]);

        (new GenerateBulkBillsJob($batch->id, $period, $customerIds))->handle(app(BillBatchService::class));

        $file = BillBatchFile::query()->where('bill_batch_id', $batch->id)->where('kind', 'bulk')->firstOrFail();
        $this->assertNull($file->zone_id);
        $this->assertSame(3, $file->bill_count);
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($file->path));
    }

    public function test_finalize_marks_completed_and_builds_the_zip(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'queued', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 1,
        ]);

        $service = app(BillBatchService::class);
        $service->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $service->renderBulkFile($batch->id, $period, $customerIds);
        $service->finalize($batch->id, failedJobs: 0, totalJobs: 2);

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->completed_at);

        $zip = BillBatchFile::query()->where('bill_batch_id', $batch->id)->where('kind', 'zip')->firstOrFail();
        Storage::disk('local')->assertExists($zip->path);
    }

    public function test_finalize_marks_partial_when_a_zone_pdf_is_missing(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);
        $customerIds = $zone->customers()->pluck('id')->all();

        // total_zones = 2 but only one zone PDF is ever produced.
        $batch = BillBatch::create([
            'period' => $period, 'status' => 'processing', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 2,
        ]);

        $service = app(BillBatchService::class);
        $service->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $service->renderBulkFile($batch->id, $period, $customerIds);
        $service->finalize($batch->id, failedJobs: 1, totalJobs: 3);

        $this->assertSame('partial', $batch->fresh()->status);
    }

    public function test_finalize_marks_failed_when_no_bulk_pdf_landed(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'processing', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 1,
        ]);

        // Only the zone PDF, never the bulk one.
        app(BillBatchService::class)->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        app(BillBatchService::class)->finalize($batch->id, failedJobs: 1, totalJobs: 2);

        $this->assertSame('failed', $batch->fresh()->status);
    }

    public function test_cancel_stops_an_in_flight_run_and_discards_its_artifacts(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'processing', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 1,
        ]);
        // A zone PDF that landed before the cancel took effect.
        app(BillBatchService::class)->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $file = BillBatchFile::query()->where('bill_batch_id', $batch->id)->firstOrFail();
        Storage::disk('local')->assertExists($file->path);

        $this->actingAsRole('manager');
        $this->post(route('manuscripts.bills.cancel', $batch->uuid))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('cancelled', $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame(0, $batch->files()->count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_cancel_is_a_noop_on_an_already_finished_run(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'completed', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 1,
        ]);
        app(BillBatchService::class)->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);

        $this->actingAsRole('manager');
        $this->post(route('manuscripts.bills.cancel', $batch->uuid))->assertRedirect();

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->files()->count());
    }

    public function test_finalize_leaves_a_cancelled_run_cancelled(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'cancelled', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 1,
        ]);

        app(BillBatchService::class)->finalize($batch->id, failedJobs: 0, totalJobs: 2);

        $this->assertSame('cancelled', $batch->fresh()->status);
    }

    public function test_destroy_deletes_the_run_and_every_artifact(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'completed', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 1,
        ]);
        $service = app(BillBatchService::class);
        $service->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $service->renderBulkFile($batch->id, $period, $customerIds);
        $paths = $batch->files()->pluck('path');
        $this->assertGreaterThan(0, $paths->count());

        $this->actingAsRole('manager');
        $this->delete(route('manuscripts.bills.destroy', $batch->uuid))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(BillBatch::find($batch->id));
        $this->assertSame(0, BillBatchFile::query()->where('bill_batch_id', $batch->id)->count());
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_cancel_and_destroy_are_denied_to_a_worker(): void
    {
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);
        $batch = BillBatch::create([
            'period' => $period, 'status' => 'processing', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 1,
        ]);

        $this->actingAsRole('worker');
        $this->post(route('manuscripts.bills.cancel', $batch->uuid))->assertForbidden();
        $this->delete(route('manuscripts.bills.destroy', $batch->uuid))->assertForbidden();

        $this->assertNotNull(BillBatch::find($batch->id));
    }

    /**
     * Regression: generating September bills on Aug 31 rendered a
     * "October 2026" label — `Carbon::createFromFormat('Y-m', '2026-09')`
     * kept the current day (31), and 2026-09-31 rolls to 2026-10-01. The
     * `!Y-m` fix pins the parse to the 1st.
     */
    public function test_bill_period_label_does_not_roll_forward_when_generated_on_a_31st(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');

        try {
            CompanyFactory::new()->create();
            $zone = ZoneFactory::new()->create(['name' => 'ZZ '.Str::random(6)]);
            $customer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
            ManuscriptFactory::new()->forPeriod('2026-09')->create([
                'customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500,
            ]);

            $bills = app(ManuscriptService::class)->billDataForCustomers(collect([$customer]), '2026-09');

            $this->assertNotEmpty($bills);
            $this->assertSame('September 2026', $bills[0]['period_label']);
            $this->assertSame('05 September 2026', $bills[0]['deadline']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_download_streams_a_stored_artifact_and_denies_a_worker(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada', 'Ben']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'completed', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 1,
        ]);
        app(BillBatchService::class)->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $file = BillBatchFile::query()->where('bill_batch_id', $batch->id)->firstOrFail();

        $this->actingAsRole('manager');
        $ok = $this->get(route('manuscripts.bills.download', [$batch->uuid, $file->uuid]));
        $ok->assertOk();
        $this->assertSame('application/pdf', $ok->headers->get('content-type'));

        $this->actingAsRole('worker');
        $this->get(route('manuscripts.bills.download', [$batch->uuid, $file->uuid]))->assertForbidden();
    }

    public function test_download_is_not_capped_by_the_exports_rate_limit(): void
    {
        Storage::fake('local');
        [$zone, $period] = $this->seedZoneWithRecipients(['Ada']);
        $customerIds = $zone->customers()->pluck('id')->all();

        $batch = BillBatch::create([
            'period' => $period, 'status' => 'completed', 'density' => 1, 'template' => 'classic',
            'total_bills' => 1, 'total_zones' => 1,
        ]);
        app(BillBatchService::class)->renderZoneFile($batch->id, $period, $zone->id, $zone->name, $customerIds);
        $file = BillBatchFile::query()->where('bill_batch_id', $batch->id)->firstOrFail();

        $this->actingAsRole('manager');
        $downloadUrl = route('manuscripts.bills.download', [$batch->uuid, $file->uuid]);

        for ($attempt = 0; $attempt <= config('rate-limits.exports.max_attempts'); $attempt++) {
            $this->get($downloadUrl)->assertOk();
        }
    }

    /**
     * N+1 regression: files loaded through the relation carry their parent
     * batch, so BillBatchFile::downloadName() (called once per zone file
     * while building the zip) doesn't re-fetch the batch for each file.
     */
    public function test_loaded_files_share_their_parent_batch_instead_of_refetching_it(): void
    {
        $batch = BillBatch::create([
            'period' => '2026-09', 'status' => 'completed', 'density' => 1, 'template' => 'classic',
            'total_bills' => 2, 'total_zones' => 2,
        ]);

        foreach (['North', 'South'] as $zoneName) {
            BillBatchFile::query()->create([
                'bill_batch_id' => $batch->id,
                'kind' => 'zone',
                'zone_name' => $zoneName,
                'disk' => 'local',
                'path' => "bills/{$zoneName}.pdf",
                'bill_count' => 1,
            ]);
        }

        $loaded = BillBatch::with('files')->findOrFail($batch->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $names = $loaded->files->map(fn (BillBatchFile $file): string => $file->downloadName())->all();
        DB::disableQueryLog();

        $this->assertCount(2, $names);
        $this->assertSame([], DB::getQueryLog());
    }
}
