<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Imports\ZonesImport;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /zones/import/template (ZoneController::importTemplate(),
 * App\Exports\ZoneImportTemplateExport) — the "download a blank,
 * correctly-formatted spreadsheet first" counterpart to
 * tests/Feature/Web/ZoneImportTest.php's upload tests. Same
 * DatabaseTransactions + session-auth setup as that file.
 */
class ZoneImportTemplateTest extends TestCase
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
     * capturing its content.
     */
    private function downloadAndSave(string $uri): string
    {
        $response = $this->get($uri);
        $response->assertOk();

        $path = storage_path('app/'.uniqid('ztemplate_test_', true).'.xlsx');
        file_put_contents($path, $response->streamedContent());

        $this->beforeApplicationDestroyed(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    public function test_manager_can_download_the_zone_import_template_with_exact_headers(): void
    {
        $this->actingAsRole('manager');

        $path = $this->downloadAndSave('/zones/import/template');

        // Raw (un-heading-parsed) read so the assertion checks the exact
        // header row byte-for-byte against ZonesImport::COLUMNS.
        $rawSheets = Excel::toCollection(null, $path);

        $this->assertCount(1, $rawSheets);

        $headerRow = $rawSheets->first()->first();
        $this->assertSame(ZonesImport::COLUMNS, $headerRow->values()->all());

        // At least one real example data row beyond the header.
        $this->assertGreaterThan(1, $rawSheets->first()->count());

        // Round-trips through the real import class ZoneController::
        // import() uses.
        $typedSheets = Excel::toCollection(new ZonesImport, $path);
        $firstDataRow = $typedSheets->first()->first();
        $this->assertNotNull($firstDataRow);
        $this->assertArrayHasKey('name', $firstDataRow->toArray());
        $this->assertArrayHasKey('town', $firstDataRow->toArray());
    }

    public function test_agent_gets_a_403_downloading_the_zone_import_template(): void
    {
        $this->actingAsRole('agent');

        $this->get('/zones/import/template')->assertForbidden();
    }

    public function test_worker_gets_a_403_downloading_the_zone_import_template(): void
    {
        $this->actingAsRole('worker');

        $this->get('/zones/import/template')->assertForbidden();
    }
}
