<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Services\TenantExportService;
use App\Services\TenantImportService;
use App\Support\TenantArchive;
use Database\Factories\CustomerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;
use ZipArchive;

/**
 * tenant:export / tenant:import (App\Services\TenantExportService,
 * TenantImportService): the swecom dev → production cutover tooling.
 *
 * No DatabaseTransactions: the import builds a schema and commits across
 * connections, exactly as it will in production. Every test works on its
 * own disposable tenant (UsesDisposableTenant) and its own committed user,
 * and removes both in tearDown — swecom is never touched.
 */
class TenantTransferTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    private User $user;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('xfer');
        $this->user = User::factory()->create();

        tenancy()->initialize($this->tenant);
        TenantUser::query()->create(['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'role' => 'admin']);
        CustomerFactory::new()->count(3)->create();
        tenancy()->end();

        // A hand-made table no migration builds — like swecom's
        // manuscripts_2026_08_snapshot — must come across too.
        $snapshot = TenantArchive::qualified('tenant'.$this->tenant->id, 'manual_snapshot');
        DB::connection('pgsql')->statement("CREATE TABLE {$snapshot} (id bigint NOT NULL, amount numeric(12,2), note text, taken_at timestamp with time zone)");
        DB::connection('pgsql')->statement("INSERT INTO {$snapshot} VALUES (1, 2500.00, 'August', '2026-08-31 23:00:00+00'), (2, NULL, NULL, NULL)");

        $this->user->createToken('api');
        File::put($this->filesRoot().'/public/logo.txt', 'swecom logo');

        $this->archive = storage_path('framework/testing/'.$this->tenant->id.'.cncms');
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        $this->removeTenantEverywhere();
        @unlink($this->archive);

        parent::tearDown();
    }

    private function filesRoot(): string
    {
        $root = storage_path('tenant'.$this->tenant->id.'/app');
        File::ensureDirectoryExists($root.'/public');

        return $root;
    }

    /** Drops the schema, the central rows and the files — the "fresh production server" state. */
    private function removeTenantEverywhere(): void
    {
        $db = DB::connection('pgsql');
        $schema = 'tenant'.$this->tenant->id;

        if (TenantArchive::schemaExists($db, $schema)) {
            $db->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }

        PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->delete();
        TenantUserIndex::query()->where('tenant_id', $this->tenant->id)->delete();
        $db->table('domains')->where('tenant_id', $this->tenant->id)->delete();
        $db->table('tenants')->where('id', $this->tenant->id)->delete();
        $db->table('users')->where('id', $this->user->id)->delete();
        File::deleteDirectory(storage_path('tenant'.$this->tenant->id));
    }

    /**
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $db = DB::connection('pgsql');
        $schema = 'tenant'.$this->tenant->id;
        $fingerprint = [];

        foreach (TenantArchive::tables($db, $schema) as $table) {
            if ($table !== 'migrations') {
                $fingerprint[$table] = TenantArchive::checksum($db->select('SELECT * FROM '.TenantArchive::qualified($schema, $table)), TenantArchive::columns($db, $schema, $table));
            }
        }

        return $fingerprint;
    }

    private function import(): array
    {
        return app(TenantImportService::class)->import($this->archive, fn (string $message) => null);
    }

    public function test_round_trip_restores_every_row_user_token_and_file(): void
    {
        $before = $this->fingerprint();
        $passwordHash = $this->user->password;

        $this->artisan('tenant:export', ['tenant' => $this->tenant->id, '--output' => $this->archive])
            ->expectsConfirmation('No maintenance window is running, so staff could record work after this snapshot that will not be in it. Export anyway?', 'yes')
            ->assertSuccessful();

        $this->removeTenantEverywhere();
        $this->assertNull(Tenant::find($this->tenant->id));

        $this->artisan('tenant:import', ['archive' => $this->archive])->assertSuccessful();

        $this->assertNotNull(Tenant::find($this->tenant->id));
        $this->assertSame($before, $this->fingerprint());
        $this->assertArrayHasKey('manual_snapshot', $before, 'the hand-made table is part of the comparison');
        $this->assertSame(
            ['id' => 'bigint', 'amount' => 'numeric(12,2)', 'note' => 'text', 'taken_at' => 'timestamp with time zone'],
            array_column(TenantArchive::definition(DB::connection('pgsql'), 'tenant'.$this->tenant->id, 'manual_snapshot'), 'type', 'name'),
        );
        $this->assertSame($passwordHash, User::query()->findOrFail($this->user->id)->password);
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->count());
        $this->assertTrue(TenantUserIndex::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->exists());
        $this->assertSame('swecom logo', File::get(storage_path('tenant'.$this->tenant->id.'/app/public/logo.txt')));

        tenancy()->initialize(Tenant::findOrFail($this->tenant->id));
        $this->assertSame(3, DB::connection('tenant')->table('customers')->count());

        // Sequences moved past the imported ids: new rows don't collide.
        CustomerFactory::new()->create();
        $this->assertSame(4, DB::connection('tenant')->table('customers')->count());
    }

    public function test_import_refuses_when_the_tenant_already_exists(): void
    {
        app(TenantExportService::class)->export($this->tenant->id, $this->archive);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nothing was imported:.*already exists here/s');

        $this->import();
    }

    public function test_import_refuses_an_archive_from_a_different_app_key(): void
    {
        app(TenantExportService::class)->export($this->tenant->id, $this->archive);
        $this->removeTenantEverywhere();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        try {
            $this->import();
            $this->fail('The import should have refused the archive.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not decrypt', $e->getMessage());
        }

        $this->assertNull(Tenant::find($this->tenant->id));
    }

    public function test_a_failed_verification_rolls_back_everything(): void
    {
        app(TenantExportService::class)->export($this->tenant->id, $this->archive);
        $this->removeTenantEverywhere();

        $zip = new ZipArchive;
        $zip->open($this->archive);
        $zip->setPassword(TenantArchive::password());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['tables']['tenant.customers']['checksum'] = str_repeat('0', 64);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        $zip->close();

        try {
            $this->import();
            $this->fail('The import should have failed verification.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Verification failed for [tenant.customers]', $e->getMessage());
        }

        $this->assertNull(Tenant::find($this->tenant->id));
        $this->assertFalse(TenantArchive::schemaExists(DB::connection('pgsql'), 'tenant'.$this->tenant->id));
        $this->assertNull(User::query()->find($this->user->id));
        $this->assertFalse(TenantUserIndex::query()->where('tenant_id', $this->tenant->id)->exists());
        $this->assertDirectoryDoesNotExist(storage_path('tenant'.$this->tenant->id.'/app'));
    }
}
