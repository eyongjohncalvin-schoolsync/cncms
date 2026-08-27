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
 * Bulk zone import via .xlsx (App\Services\ZoneImportService,
 * ZoneController::import()). Same DatabaseTransactions + session-auth
 * setup as tests/Feature/Web/ZoneTest.php.
 */
class ZoneImportTest extends TestCase
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function spreadsheet(array $rows, array $headings = ['name', 'town']): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray(array_map(fn ($h) => $row[$h] ?? null, $headings), null, 'A'.($i + 2));
        }

        $path = storage_path('app/'.uniqid('ztest_', true).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $this->beforeApplicationDestroyed(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'zones.xlsx', null, null, true);
    }

    public function test_manager_can_import_zones_from_a_valid_spreadsheet(): void
    {
        $this->actingAsRole('manager');

        $file = $this->spreadsheet([
            ['name' => 'ZIMP-A', 'town' => 'KUMBA 3'],
            ['name' => 'ZIMP-B', 'town' => 'KUMBA 3'],
        ]);

        $response = $this->post('/zones/import', ['file' => $file]);

        $response->assertRedirect('/zones');
        $response->assertSessionHas('success');
        $response->assertSessionHas('import', function (array $import): bool {
            return $import['type'] === 'zones'
                && $import['succeeded_count'] === 2
                && $import['failed_count'] === 0;
        });

        $this->assertDatabaseHas('zones', ['name' => 'ZIMP-A', 'town' => 'KUMBA 3']);
        $this->assertDatabaseHas('zones', ['name' => 'ZIMP-B', 'town' => 'KUMBA 3']);

        $upload = Upload::query()->where('type', 'zones')->latest('id')->first();
        $this->assertNotNull($upload);
        $this->assertSame('completed', $upload->status);
        $this->assertSame(2, $upload->succeeded_count);
        $this->assertSame(0, $upload->failed_count);
    }

    public function test_a_spreadsheet_with_a_duplicate_zone_name_is_rejected_appropriately(): void
    {
        $this->actingAsRole('manager');

        $existing = ZoneFactory::new()->create(['name' => 'ZIMP-DUP']);

        $file = $this->spreadsheet([
            ['name' => 'ZIMP-DUP', 'town' => 'KUMBA 3'], // already exists — must fail
            ['name' => 'ZIMP-NEW', 'town' => 'KUMBA 3'], // new — must succeed
        ]);

        $response = $this->post('/zones/import', ['file' => $file]);

        $response->assertRedirect('/zones');
        $response->assertSessionHas('import', function (array $import): bool {
            return $import['succeeded_count'] === 1 && $import['failed_count'] === 1;
        });

        // The duplicate did not create a second row for the same name.
        $this->assertSame(1, \App\Models\Zone::query()->where('name', 'ZIMP-DUP')->count());
        $this->assertDatabaseHas('zones', ['id' => $existing->id, 'name' => 'ZIMP-DUP']);
        $this->assertDatabaseHas('zones', ['name' => 'ZIMP-NEW']);
    }

    public function test_agent_gets_a_403_importing_zones(): void
    {
        $this->actingAsRole('agent');

        $file = $this->spreadsheet([['name' => 'ZIMP-FORBIDDEN', 'town' => 'KUMBA 3']]);

        $this->post('/zones/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('zones', ['name' => 'ZIMP-FORBIDDEN']);
    }

    public function test_worker_gets_a_403_importing_zones(): void
    {
        $this->actingAsRole('worker');

        $file = $this->spreadsheet([['name' => 'ZIMP-FORBIDDEN2', 'town' => 'KUMBA 3']]);

        $this->post('/zones/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('zones', ['name' => 'ZIMP-FORBIDDEN2']);
    }
}
