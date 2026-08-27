<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\Upload;
use App\Models\User;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
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
            'name' => 'CIMP Customer A',
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 1500,
            'phone' => '677440670',
        ]);
        $this->assertDatabaseHas('customers', [
            'name' => 'CIMP Customer B',
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

        $this->assertDatabaseHas('customers', ['name' => 'CIMP Good Row', 'zone_id' => $zone->id]);
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP Bad Zone Row']);
    }

    public function test_agent_gets_a_403_importing_customers(): void
    {
        $this->actingAsRole('agent');

        ZoneFactory::new()->create(['name' => 'CIMP-AGENT-ZONE']);

        $file = $this->spreadsheet([
            ['name' => 'CIMP Forbidden', 'zone' => 'CIMP-AGENT-ZONE', 'bill' => 2500],
        ]);

        $this->post('/customers/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP Forbidden']);
    }

    public function test_worker_gets_a_403_importing_customers(): void
    {
        $this->actingAsRole('worker');

        ZoneFactory::new()->create(['name' => 'CIMP-WORKER-ZONE']);

        $file = $this->spreadsheet([
            ['name' => 'CIMP Forbidden Worker', 'zone' => 'CIMP-WORKER-ZONE', 'bill' => 2500],
        ]);

        $this->post('/customers/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'CIMP Forbidden Worker']);
    }
}
