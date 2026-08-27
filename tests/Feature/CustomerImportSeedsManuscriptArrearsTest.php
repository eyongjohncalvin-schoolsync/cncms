<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Models\Upload;
use App\Models\User;
use App\Models\Zone;
use App\Services\CustomerImportService;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
 * Runs the real manuscript:calculate command, which owns its own
 * tenancy()->initialize()/end() lifecycle. Mirrors
 * tests/Feature/ManuscriptCalculateTest.php's
 * test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run
 * pattern exactly, including its explicit (non-transactional) fixture
 * cleanup in a finally block: DatabaseTransactions only wraps the default
 * `pgsql` connection, and Stancl's tenancy()->end() purges (disconnects)
 * the dynamically-created `tenant` connection, which would silently roll
 * back any fixtures still sitting in an open transaction on it. So, like
 * that test, this one does real (uncommitted-transaction) writes against
 * the real swecom tenant schema and cleans them up itself afterwards.
 */
class CustomerImportSeedsManuscriptArrearsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_customer_imported_with_a_seeded_others_balance_carries_it_into_their_first_manuscript(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        Storage::fake('local');

        tenancy()->initialize($tenant);

        $zone = ZoneFactory::new()->create(['name' => 'MANIMP ZONE']);

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

        $result = app(CustomerImportService::class)->import($file, $user);

        $this->assertCount(1, $result['succeeded']);
        $this->assertSame([], $result['failed']);

        $customer = Customer::query()->where('name', 'Manuscript Import Customer')->firstOrFail();
        $uploadUuid = $result['upload_uuid'];

        // Before manuscript:calculate: the seed balance landed exactly on
        // customers.others, untouched.
        $this->assertEqualsWithDelta(4000.0, (float) $customer->others, 0.001);

        tenancy()->end();

        $period = '2026-06';

        try {
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
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
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            Manuscript::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Upload::query()->where('uuid', $uploadUuid)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
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
