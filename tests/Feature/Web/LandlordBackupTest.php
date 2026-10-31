<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Jobs\RunDatabaseBackupJob;
use App\Mail\BackupDownloadedMail;
use App\Models\LandlordActivity;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The landlord Backups page: listing the server's dumps, "Back up now", and
 * the password-locked download. Dumps are fake files in a temporary
 * BACKUP_DIR; central data only, so DatabaseTransactions rolls back.
 */
class LandlordBackupTest extends TestCase
{
    use DatabaseTransactions;

    private User $landlord;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/cncms-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->directory);
        config(['filesystems.backups_path' => $this->directory]);

        User::query()->where('is_landlord', true)->update(['is_landlord' => false]);

        $this->landlord = User::factory()->create(['password' => 'secret-password-1']);
        $this->landlord->forceFill([
            'is_landlord' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($this->landlord);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        app(DatabaseBackupService::class)->release();

        parent::tearDown();
    }

    private function dump(string $name, string $contents = 'dump', ?int $madeAt = null): string
    {
        $path = "{$this->directory}/{$name}";
        file_put_contents($path, $contents);
        touch($path, $madeAt ?? time());

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function downloadForm(array $overrides = []): array
    {
        return array_merge([
            'password' => 'secret-password-1',
            'zip_password' => 'long-file-password',
            'zip_password_confirmation' => 'long-file-password',
        ], $overrides);
    }

    public function test_the_page_lists_only_backup_files_newest_first(): void
    {
        $this->dump('cncms-2026-10-30-0315.sql.gz', 'older');
        $this->dump('cncms-2026-10-31-0315.sql.gz', 'newer');
        $this->dump('notes.txt');
        $this->dump('cncms-latest.sql.gz');

        $this->get('/landlord/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Backups/Index')
                ->has('backups', 2)
                ->where('backups.0.name', 'cncms-2026-10-31-0315.sql.gz')
                ->where('backups.0.bytes', 5)
                ->where('backups.1.name', 'cncms-2026-10-30-0315.sql.gz')
                ->where('is_stale', false)
                ->where('has_two_factor', true));
    }

    public function test_the_page_warns_when_the_newest_backup_is_too_old(): void
    {
        $this->dump('cncms-2026-10-28-0315.sql.gz', madeAt: now()->subHours(DatabaseBackupService::STALE_HOURS + 1)->getTimestamp());

        $this->get('/landlord/backups')->assertInertia(fn (Assert $page) => $page->where('is_stale', true));
    }

    public function test_backups_are_forbidden_to_non_landlords(): void
    {
        $this->dump('cncms-2026-10-31-0315.sql.gz');
        $this->actingAs(User::factory()->create());

        $this->get('/landlord/backups')->assertForbidden();
        $this->post('/landlord/backups')->assertForbidden();
        $this->postJson('/landlord/backups/cncms-2026-10-31-0315.sql.gz/download', $this->downloadForm())->assertForbidden();
    }

    public function test_a_download_gives_a_zip_that_only_the_chosen_password_opens(): void
    {
        Mail::fake();
        $this->dump('cncms-2026-10-31-0315.sql.gz', 'the whole database');
        $otherLandlord = User::factory()->create();
        $otherLandlord->forceFill(['is_landlord' => true])->save();

        $response = $this->postJson('/landlord/backups/cncms-2026-10-31-0315.sql.gz/download', $this->downloadForm());

        $response->assertOk()->assertDownload('cncms-2026-10-31-0315.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statName('cncms-2026-10-31-0315.sql.gz')['encryption_method']);
        $this->assertFalse($zip->getFromName('cncms-2026-10-31-0315.sql.gz'), 'The zip opened without its password.');
        $zip->setPassword('long-file-password');
        $this->assertSame('the whole database', $zip->getFromName('cncms-2026-10-31-0315.sql.gz'));
        $zip->close();

        $entry = LandlordActivity::query()->where('action', 'backup.downloaded')->sole();
        $this->assertSame($this->landlord->id, $entry->user_id);
        $this->assertSame('cncms-2026-10-31-0315.sql.gz', $entry->subject_id);

        Mail::assertQueued(BackupDownloadedMail::class, 2);
        Mail::assertQueued(BackupDownloadedMail::class, fn (BackupDownloadedMail $mail) => $mail->hasTo($otherLandlord->email)
            && $mail->downloadedBy === $this->landlord->name
            && $mail->fileName === 'cncms-2026-10-31-0315.sql.gz');
    }

    public function test_a_download_needs_two_step_login_on_the_account(): void
    {
        Mail::fake();
        $this->dump('cncms-2026-10-31-0315.sql.gz');
        $this->landlord->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->postJson('/landlord/backups/cncms-2026-10-31-0315.sql.gz/download', $this->downloadForm())->assertForbidden();

        Mail::assertNothingQueued();
        $this->assertFalse(LandlordActivity::query()->where('action', 'backup.downloaded')->exists());
    }

    public function test_a_download_needs_the_account_password_and_a_long_matching_file_password(): void
    {
        $this->dump('cncms-2026-10-31-0315.sql.gz');
        $url = '/landlord/backups/cncms-2026-10-31-0315.sql.gz/download';

        $this->postJson($url, $this->downloadForm(['password' => 'wrong']))->assertJsonValidationErrors('password');
        $this->postJson($url, $this->downloadForm(['zip_password' => 'short', 'zip_password_confirmation' => 'short']))->assertJsonValidationErrors('zip_password');
        $this->postJson($url, $this->downloadForm(['zip_password_confirmation' => 'something-else-entirely']))->assertJsonValidationErrors('zip_password');
    }

    public function test_only_listed_backup_files_can_be_downloaded(): void
    {
        $this->dump('notes.txt');

        $this->postJson('/landlord/backups/cncms-2026-01-01-0000.sql.gz/download', $this->downloadForm())->assertNotFound();
        $this->postJson('/landlord/backups/notes.txt/download', $this->downloadForm())->assertNotFound();
        $this->postJson('/landlord/backups/..%2F..%2F.env/download', $this->downloadForm())->assertNotFound();
    }

    public function test_back_up_now_queues_one_backup_at_a_time(): void
    {
        Queue::fake();

        $this->post('/landlord/backups')->assertSessionHas('success');
        $this->post('/landlord/backups')->assertSessionHas('error');

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        Queue::assertPushed(RunDatabaseBackupJob::class, fn (RunDatabaseBackupJob $job) => $job->requestedBy === $this->landlord->id);
        $this->assertTrue(LandlordActivity::query()->where('action', 'backup.requested')->exists());
        $this->get('/landlord/backups')->assertInertia(fn (Assert $page) => $page->where('is_running', true));
    }

    public function test_the_backup_job_runs_the_backup_script_and_frees_the_slot(): void
    {
        Process::fake();
        $backups = app(DatabaseBackupService::class);
        $backups->claim();

        (new RunDatabaseBackupJob($this->landlord->id))->handle($backups);

        Process::assertRan(fn ($process) => $process->command === ['bash', base_path('deploy/scripts/backup-db.sh')]
            && $process->environment['BACKUP_DIR'] === $this->directory);
        $this->assertFalse($backups->isRunning());
        $this->assertSame($this->landlord->id, LandlordActivity::query()->where('action', 'backup.created')->sole()->user_id);
    }

    public function test_a_failed_backup_is_logged_and_frees_the_slot(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'pg_dump: connection refused', exitCode: 1)]);
        $backups = app(DatabaseBackupService::class);
        $backups->claim();

        try {
            (new RunDatabaseBackupJob($this->landlord->id))->handle($backups);
            $this->fail('The failed backup did not throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }

        $this->assertFalse($backups->isRunning());
        $this->assertStringContainsString('connection refused', LandlordActivity::query()->where('action', 'backup.failed')->sole()->details['error']);
    }
}
