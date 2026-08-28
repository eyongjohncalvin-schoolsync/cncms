<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\User;
use App\Services\CustomerImportService;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * The single most important test in the bulk-import feature.
 *
 * A new cable operator onboarding onto CNCMS almost always already has
 * customers with pre-existing debt from their old records. The customer
 * import spreadsheet's `others` column is the mechanism for bringing that
 * debt in (App\Services\ManuscriptCalculator's class doc comment: `others`
 * is applied exactly once, on a customer's very first manuscript:calculate
 * run, then never referenced again). This test proves the WHOLE pipeline
 * end to end — a real .xlsx parsed by the real maatwebsite/excel package,
 * through CustomerImportService, through the exact same
 * CustomerService::create() write path a manual form submission uses, into
 * customers.others, and then through a real `php artisan
 * manuscript:calculate` run into manuscripts.total_arrears — not just that
 * the `others` column is mapped to a fillable attribute.
 *
 * 2026-08-28 incident this closes: this file used to initialize tenancy
 * against the REAL `swecom` tenant and run manuscript:calculate against it
 * directly, cleaning up only its own single imported customer's manuscript
 * row afterward — never the ~445 OTHER real customers the unscoped command
 * also wrote a period='2026-06' row for on every green run. Traced (2026-
 * 08-28, task-scheduler.md addendum) as the source of 892 real manuscript
 * rows (periods 2026-05/06, no command_runs audit trail) bulk-inserted
 * against real swecom weeks after the fact — the same incident class as
 * the two files already fixed 2026-08-27 and the third fixed earlier today
 * (ManuscriptCalculateTest.php). Converted to UsesDisposableTenant — see
 * tests/Feature/Web/ManuscriptTest.php's identical pattern/reasoning,
 * including the "commit before provisioning" fix DatabaseTransactions
 * requires (its CREATE SCHEMA runs on the same connection DatabaseTransactions
 * wraps in an outer, uncommitted transaction — invisible to the separate
 * `tenant` session the migration step then needs to see it from).
 */
class CustomerImportSeedsManuscriptArrearsTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDisposableTenant;

    public function test_a_customer_imported_with_a_seeded_others_balance_carries_it_into_their_first_manuscript(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('cismat');
        $user = $this->provisionDisposableTenantAdmin($tenant, 'admin');

        Storage::fake('local');

        // Built before tenancy()->initialize(): Stancl's filesystem
        // bootstrapper rewrites storage_path() to a per-tenant directory
        // once tenancy is active, and unlike real swecom (which already has
        // one from normal use), a brand-new disposable tenant has no
        // storage/<tenant>/app/ directory on disk yet — writing here first
        // avoids that entirely.
        $file = $this->buildSpreadsheet([[
            'name' => 'Manuscript Import Customer',
            'phone' => '677123456',
            'level' => 'normal',
            'location' => 'Test Street',
            'zone' => 'MANIMP ZONE',
            'bill' => 2500,
            // The pre-existing balance this customer is bringing in from
            // their old records — the whole point of this test.
            'others' => 4000,
            'status' => 'active',
        ]]);

        tenancy()->initialize($tenant);

        $zone = ZoneFactory::new()->create(['name' => 'MANIMP ZONE']);

        $result = app(CustomerImportService::class)->import($file, $user);

        $this->assertCount(1, $result['succeeded']);
        $this->assertSame([], $result['failed']);

        $customer = Customer::query()->where('name', 'Manuscript Import Customer')->firstOrFail();

        // Before manuscript:calculate: the seed balance landed exactly on
        // customers.others, untouched.
        $this->assertEqualsWithDelta(4000.0, (float) $customer->others, 0.001);

        tenancy()->end();

        $period = Carbon::now()->format('Y-m');

        try {
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => $tenant->id,
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $manuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($manuscript, 'expected a manuscript row for the imported customer');

            // ManuscriptCalculator, first run: previousArrears = others
            // (4000, not 0). No payments were made, so:
            //   total_arrears = others + bill = 4000 + 2500 = 6500
            //   credit        = 0
            //   total_bill    = bill + total_arrears - credit = 2500 + 6500 = 9000
            $this->assertEqualsWithDelta(6500.0, (float) $manuscript->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $manuscript->credit, 0.001);
            $this->assertEqualsWithDelta(9000.0, (float) $manuscript->total_bill, 0.001);
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $tenant->delete();
            User::query()->whereKey($user->id)->delete();

            if (DB::connection()->transactionLevel() === 0) {
                DB::connection()->beginTransaction();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildSpreadsheet(array $rows): UploadedFile
    {
        $headings = ['name', 'phone', 'level', 'location', 'zone', 'bill', 'others', 'status'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray(array_map(fn ($h) => $row[$h] ?? null, $headings), null, 'A'.($i + 2));
        }

        $path = storage_path('app/'.uniqid('manuscript_import_', true).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $this->beforeApplicationDestroyed(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'customers.xlsx', null, null, true);
    }
}
