<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\Upload;
use App\Models\User;
use App\Services\CustomerImportService;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Bulk customer import via .xlsx (App\Services\CustomerImportService,
 * CustomerController::import()). Same DatabaseTransactions + session-auth
 * setup as tests/Feature/Web/CustomerTest.php.
 *
 * The most important scenario for this feature — a customer imported with
 * a non-zero `others` seed balance actually flowing into their first
 * manuscript:calculate run — is covered separately in
 * tests/Feature/CustomerImportSeedsManuscriptArrearsTest.php, since it
 * needs the real manuscript:calculate command's own tenancy lifecycle
 * (see that file's doc comment) rather than this class's transaction-
 * wrapped setup.
 */
class CustomerImportTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    private const HEADINGS = ['name', 'phone', 'level', 'location', 'zone', 'bill', 'others', 'status'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function spreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADINGS, null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray(array_map(fn ($h) => $row[$h] ?? null, self::HEADINGS), null, 'A'.($i + 2));
        }

        $path = storage_path('app/'.uniqid('ctest_', true).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $this->beforeApplicationDestroyed(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'customers.xlsx', null, null, true);
    }

    /**
     * A sheet bigger than CustomerImportService::SYNC_ROW_LIMIT is imported
     * by the queue (ImportCustomersJob — run at once by the test queue), and
     * the Customers page shows its report.
     */
    public function test_a_big_sheet_imports_in_the_background_and_reports_on_the_customers_page(): void
    {
        $this->actingAsRole('manager');
        ZoneFactory::new()->create(['name' => 'CBIG-ZONE']);

        $rows = array_map(fn (int $i): array => ['name' => "CBIG Customer {$i}", 'zone' => 'CBIG-ZONE', 'bill' => 2500, 'status' => 'active'], range(1, CustomerImportService::SYNC_ROW_LIMIT + 1));
        $rows[] = ['name' => 'CBIG Lost', 'zone' => 'NO-SUCH-ZONE', 'bill' => 2500, 'status' => 'active'];

        $this->post('/customers/import', ['file' => $this->spreadsheet($rows)])
            ->assertRedirect('/customers')
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'in the background'));

        $upload = Upload::query()->where('type', 'customers')->latest('id')->firstOrFail();
        $this->assertSame(['completed', CustomerImportService::SYNC_ROW_LIMIT + 1, 1], [$upload->status, $upload->succeeded_count, $upload->failed_count]);

        $this->get('/customers')->assertInertia(fn ($page) => $page
            ->where('background_import.status', 'completed')
            ->where('background_import.report.succeeded_count', CustomerImportService::SYNC_ROW_LIMIT + 1)
            ->where('background_import.report.failed.0.reason', fn (string $reason): bool => str_contains($reason, 'NO-SUCH-ZONE')));
    }

    public function test_manager_can_import_customers_from_a_valid_spreadsheet(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create(['name' => 'CIMP-ZONE']);

        $file = $this->spreadsheet([
            [
                'name' => 'CIMP Customer A',
                'phone' => '677440670',
                'level' => 'normal',
                'location' => 'Main Street',
                'zone' => 'CIMP-ZONE',
                'bill' => 2500,
                'others' => 1500,
                'status' => 'active',
            ],
            [
                'name' => 'CIMP Customer B',
                'phone' => '(67) 321-7927', // messy real-world format — must not be rejected for this alone
                'level' => 'Vip',
                'location' => null,
                'zone' => 'CIMP-ZONE',
                'bill' => 5000,
                'others' => 0,
                'status' => 'active',
            ],
        ]);

        $response = $this->post('/customers/import', ['file' => $file]);

        $response->assertRedirect('/customers');
        $response->assertSessionHas('success');
        $response->assertSessionHas('import', function (array $import): bool {
            return $import['type'] === 'customers'
                && $import['succeeded_count'] === 2
                && $import['failed_count'] === 0;
        });

        $this->assertDatabaseHas('customers', [
            'name' => 'CIMP CUSTOMER A',
            'location' => 'MAIN STREET',
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 1500,
            'phone' => '677440670',
        ]);
        $this->assertDatabaseHas('customers', [
            'name' => 'CIMP CUSTOMER B',
            'zone_id' => $zone->id,
            'bill' => 5000,
            'level' => 'Vip',
            'phone' => '(67) 321-7927',
        ]);

        $upload = Upload::query()->where('type', 'customers')->latest('id')->first();
        $this->assertNotNull($upload);
        $this->assertSame('completed', $upload->status);
        $this->assertSame(2, $upload->succeeded_count);
    }

    public function test_a_spreadsheet_with_a_bad_zone_reference_reports_that_row_as_failed_without_blocking_the_rest(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create(['name' => 'CIMP-REAL-ZONE']);

        $file = $this->spreadsheet([
            [
                'name' => 'CIMP Good Row',
                'zone' => 'CIMP-REAL-ZONE',
                'bill' => 2500,
                'others' => 0,
                'status' => 'active',
            ],
            [
                'name' => 'CIMP Bad Zone Row',
                'zone' => 'THIS ZONE DOES NOT EXIST',
                'bill' => 2500,
                'others' => 0,
                'status' => 'active',
            ],
        ]);

        $response = $this->post('/customers/import', ['file' => $file]);

        $response->assertRedirect('/customers');
        $response->assertSessionHas('import', function (array $import): bool {
            return $import['succeeded_count'] === 1
                && $import['failed_count'] === 1
                && $import['failed'][0]['row'] === 3 // heading=1, good row=2, bad row=3
                && str_contains($import['failed'][0]['reason'], 'THIS ZONE DOES NOT EXIST');
        });

        $this->assertDatabaseHas('customers', ['name' => 'CIMP GOOD ROW', 'zone_id' => $zone->id]);
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP BAD ZONE ROW']);
    }

    public function test_a_zone_typed_in_another_case_still_matches_and_labels_are_stored_upper_case(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create(['name' => 'CIMP-CASE ZONE']);

        $file = $this->spreadsheet([
            ['name' => ' cimp  lower row ', 'zone' => 'cimp-case  zone', 'location' => 'molyko é', 'bill' => 2500, 'others' => 0, 'status' => 'active'],
        ]);

        $this->post('/customers/import', ['file' => $file])
            ->assertSessionHas('import', fn (array $import): bool => $import['succeeded_count'] === 1 && $import['failed_count'] === 0);

        $this->assertDatabaseHas('customers', ['name' => 'CIMP LOWER ROW', 'location' => 'MOLYKO É', 'zone_id' => $zone->id]);
    }

    public function test_agent_gets_a_403_importing_customers(): void
    {
        $this->actingAsRole('agent');

        ZoneFactory::new()->create(['name' => 'CIMP-AGENT-ZONE']);

        $file = $this->spreadsheet([
            ['name' => 'CIMP Forbidden', 'zone' => 'CIMP-AGENT-ZONE', 'bill' => 2500],
        ]);

        $this->post('/customers/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP FORBIDDEN']);
    }

    public function test_worker_gets_a_403_importing_customers(): void
    {
        $this->actingAsRole('worker');

        ZoneFactory::new()->create(['name' => 'CIMP-WORKER-ZONE']);

        $file = $this->spreadsheet([
            ['name' => 'CIMP Forbidden Worker', 'zone' => 'CIMP-WORKER-ZONE', 'bill' => 2500],
        ]);

        $this->post('/customers/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP FORBIDDEN WORKER']);
    }

    /**
     * N+1 regression: each distinct zone is resolved once per file, and the
     * relations create() eager-loads for its HTTP callers aren't reloaded
     * per imported row — so zone/service reads don't grow with row count.
     */
    public function test_import_does_not_look_the_zone_up_or_reload_relations_per_row(): void
    {
        $this->actingAsRole('manager');
        Cache::forget('zones:all');

        $zone = ZoneFactory::new()->create(['name' => 'CIMP-NPLUS1-ZONE']);

        $countLookups = function (int $rowCount) use ($zone): int {
            $file = $this->spreadsheet(collect(range(1, $rowCount))->map(fn (int $i): array => [
                'name' => 'CIMP N+1 Row '.$i.'-'.$rowCount,
                'zone' => $zone->name,
                'phone' => '6770000'.$rowCount.$i,
                'bill' => 2500,
                'others' => 0,
                'status' => 'active',
            ])->all());

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->post('/customers/import', ['file' => $file])
                ->assertSessionHas('import', fn (array $import): bool => $import['succeeded_count'] === $rowCount);
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return count(array_filter($queries, fn (string $sql): bool => (bool) preg_match('/^select .* from "(zones|branches|service_variants)"/', $sql)));
        };

        // Warm the (hour-long) zones:all cache so it doesn't skew the first count.
        $countLookups(1);

        $this->assertSame($countLookups(2), $countLookups(4));
    }
}
