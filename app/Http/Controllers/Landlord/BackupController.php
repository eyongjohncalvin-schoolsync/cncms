<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadBackupRequest;
use App\Jobs\RunDatabaseBackupJob;
use App\Mail\BackupDownloadedMail;
use App\Models\LandlordActivity;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Support\BusinessTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Landlord Backups page: the server's database dumps
 * (App\Services\DatabaseBackupService), "Back up now", and a
 * password-locked download. Gated by the `landlord` middleware in
 * routes/web/landlord.php.
 *
 * A dump is the whole database, so downloading needs two-step login on the
 * account, the account password again, and a password to lock the zip;
 * every download is logged and emailed to every landlord.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Landlord/Backups/Index', [
            'backups' => $this->backups->list(),
            'is_stale' => $this->backups->isStale(),
            'is_running' => $this->backups->isRunning(),
            'stale_hours' => DatabaseBackupService::STALE_HOURS,
            'has_two_factor' => $request->user()->hasTwoFactorEnabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->backups->claim()) {
            return back()->with('error', 'A backup is already being made — it appears in the list when done.');
        }

        RunDatabaseBackupJob::dispatch($request->user()->id);
        LandlordActivity::record('backup.requested', 'Asked for a database backup', 'backup');

        return back()->with('success', 'Backup started. It takes a minute or two — refresh the page to see it.');
    }

    public function download(DownloadBackupRequest $request, string $backup): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 403, 'Turn on two-step login in My account before downloading a backup.');

        $file = $this->backups->find($backup);
        abort_if($file === null, 404);

        $zip = $this->backups->lockedZip($file, (string) $request->validated('zip_password'));

        LandlordActivity::record('backup.downloaded', "Downloaded database backup {$backup}", 'backup', $backup);
        $this->notifyLandlords($user, $backup, $request->ip());

        return response()
            ->download($zip, preg_replace('/\.sql\.gz$/', '.zip', $backup), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    private function notifyLandlords(User $downloadedBy, string $backup, ?string $ipAddress): void
    {
        $occurredAt = now()->setTimezone(BusinessTimezone::WAT)->format('l d M Y, H:i');

        User::query()
            ->where('is_landlord', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->each(fn (User $landlord) => Mail::to($landlord->email)->queue(
                new BackupDownloadedMail($landlord->name, $downloadedBy->name, $backup, $occurredAt, $ipAddress),
            ));
    }
}
