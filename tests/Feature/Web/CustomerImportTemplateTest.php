<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Imports\CustomersImport;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /customers/import/template (CustomerController::importTemplate(),
 * App\Exports\CustomerImportTemplateExport) — the "download a blank,
 * correctly-formatted spreadsheet first" counterpart to
 * tests/Feature/Web/CustomerImportTest.php's upload tests. Same
 * DatabaseTransactions + session-auth setup as that file.
 */
class CustomerImportTemplateTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

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
     * Downloads the template and saves the streamed bytes to a fresh local
     * file, since Symfony's BinaryFileResponse::deleteFileAfterSend(true)
     * (set by Excel::download()) deletes the original temp file as part of
     * capturing its content. Cleaned up the same way the upload tests clean
     * up their generated spreadsheets.
     */
    private function downloadAndSave(string $uri): string
    {
        $response = $this->get($uri);
        $response->assertOk();

        $path = storage_path('app/'.uniqid('template_test_', true).'.xlsx');
        file_put_contents($path, $response->streamedContent());

        $this->beforeApplicationDestroyed(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    public function test_manager_can_download_the_customer_import_template_with_exact_headers_and_a_valid_zones_sheet(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create(['name' => 'CTMPL-REAL-ZONE']);

        $path = $this->downloadAndSave('/customers/import/template');

        // Raw (un-heading-parsed) read so the assertion checks the exact
        // header row byte-for-byte against CustomersImport::COLUMNS,
        // rather than through the heading-row snake_case filter.
        $rawSheets = Excel::toCollection(null, $path);

        $this->assertCount(2, $rawSheets, 'Expected a "Customers" sheet and a "Valid Zones" sheet.');

        $customersHeaderRow = $rawSheets->first()->first();
        $this->assertSame(CustomersImport::COLUMNS, $customersHeaderRow->values()->all());

        // At least one real example data row beyond the header.
        $this->assertGreaterThan(1, $rawSheets->first()->count());

        // The "Valid Zones" sheet lists the tenant's actual zone names
        // verbatim, so a real zone created above must appear somewhere in it.
        $zonesSheetValues = $rawSheets->get(1)->flatMap(fn ($row) => $row->values())->all();
        $this->assertContains($zone->name, $zonesSheetValues);

        // The template must also round-trip through the real import: read
        // it back with the exact same Import class CustomerController::
        // import() uses, and the example row(s) must be well-formed enough
        // to resolve to real columns.
        $typedSheets = Excel::toCollection(new CustomersImport, $path);
        $firstDataRow = $typedSheets->first()->first();
        $this->assertNotNull($firstDataRow);
        $this->assertArrayHasKey('zone', $firstDataRow->toArray());
        $this->assertArrayHasKey('bill', $firstDataRow->toArray());
    }

    public function test_agent_gets_a_403_downloading_the_customer_import_template(): void
    {
        $this->actingAsRole('agent');

        $this->get('/customers/import/template')->assertForbidden();
    }

    public function test_worker_gets_a_403_downloading_the_customer_import_template(): void
    {
        $this->actingAsRole('worker');

        $this->get('/customers/import/template')->assertForbidden();
    }
}
