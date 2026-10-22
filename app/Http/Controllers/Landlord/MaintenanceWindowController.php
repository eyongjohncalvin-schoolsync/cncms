<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceWindowRequest;
use App\Http\Requests\UpdateMaintenanceWindowRequest;
use App\Mail\MaintenanceNoticeMail;
use App\Models\LandlordActivity;
use App\Models\MaintenanceWindow;
use App\Models\TenantUserIndex;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landlord scheduling of platform maintenance windows (App\Support\
 * MaintenanceMode). Gated by the `landlord` middleware in
 * routes/web/landlord.php.
 *
 * Users hear about a window three ways: the countdown banner on every page
 * (and the login page), the mobile app's banner (X-Maintenance-* headers),
 * and — when the landlord ticks it — an email to every active tenant user
 * on schedule, change and cancel.
 */
class MaintenanceWindowController extends Controller
{
    public function index(): Response
    {
        $windows = MaintenanceWindow::query()
            ->with('creator:id,name')
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get()
            ->map(fn (MaintenanceWindow $window) => [
                ...$window->toNotice(),
                'status' => $window->status(),
                'created_by' => $window->creator?->name,
                'cancelled_at' => $window->cancelled_at?->toIso8601String(),
                'ended_at' => $window->ended_at?->toIso8601String(),
            ]);

        return Inertia::render('Landlord/Maintenance/Index', [
            'windows' => $windows,
            'recipient_count' => $this->recipients()->count(),
        ]);
    }

    public function store(StoreMaintenanceWindowRequest $request): RedirectResponse
    {
        $window = MaintenanceWindow::create([
            ...$request->safe()->only(['starts_at', 'ends_at', 'message']),
            'created_by' => $request->user()->id,
        ]);

        $emailed = $request->boolean('notify_by_email') ? $this->notify($window, 'scheduled') : 0;

        LandlordActivity::record(
            'maintenance.scheduled',
            "Scheduled maintenance {$window->starts_at->toDateTimeString()} → {$window->ends_at->toDateTimeString()} UTC",
            'maintenance',
            $window->id,
            ['message' => $window->message, 'emailed' => $emailed],
        );

        return redirect()->route('landlord.maintenance.index')->with(
            'success',
            ($window->isActive() ? 'Maintenance started.' : 'Maintenance scheduled.').$this->emailedSuffix($emailed),
        );
    }

    public function update(UpdateMaintenanceWindowRequest $request, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        abort_unless(in_array($maintenanceWindow->status(), ['scheduled', 'active'], true), 422, 'This maintenance window is already over.');

        $maintenanceWindow->update(['ends_at' => $request->validated('ends_at')]);

        $emailed = $request->boolean('notify_by_email') ? $this->notify($maintenanceWindow, 'changed') : 0;

        LandlordActivity::record('maintenance.extended', "Moved maintenance end to {$maintenanceWindow->ends_at->toDateTimeString()} UTC", 'maintenance', $maintenanceWindow->id, ['emailed' => $emailed]);

        return back()->with('success', 'End time updated.'.$this->emailedSuffix($emailed));
    }

    /**
     * Ends a running window now, or cancels one that has not started yet.
     */
    public function end(Request $request, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        $status = $maintenanceWindow->status();

        abort_unless(in_array($status, ['scheduled', 'active'], true), 422, 'This maintenance window is already over.');

        if ($status === 'active') {
            $maintenanceWindow->update(['ended_at' => now()]);
            LandlordActivity::record('maintenance.ended', 'Ended maintenance early', 'maintenance', $maintenanceWindow->id);

            return back()->with('success', 'Maintenance ended — tenants have access again.');
        }

        $maintenanceWindow->update(['cancelled_at' => now()]);
        $emailed = $request->boolean('notify_by_email') ? $this->notify($maintenanceWindow, 'cancelled') : 0;
        LandlordActivity::record('maintenance.cancelled', 'Cancelled scheduled maintenance', 'maintenance', $maintenanceWindow->id, ['emailed' => $emailed]);

        return back()->with('success', 'Maintenance cancelled.'.$this->emailedSuffix($emailed));
    }

    /**
     * Every active user who belongs to at least one tenant and has an email.
     *
     * @return Builder<User>
     */
    private function recipients(): Builder
    {
        return User::query()
            ->whereIn('id', TenantUserIndex::query()->select('user_id'))
            ->where('status', 'active')
            ->whereNotNull('email')
            ->where('email', '!=', '');
    }

    /**
     * @param  'scheduled'|'changed'|'cancelled'  $kind
     */
    private function notify(MaintenanceWindow $window, string $kind): int
    {
        $count = 0;

        $this->recipients()->select(['id', 'name', 'email'])->each(function (User $user) use ($window, $kind, &$count) {
            Mail::to($user->email)->queue(new MaintenanceNoticeMail($window, $kind, $user->name));
            $count++;
        });

        return $count;
    }

    private function emailedSuffix(int $emailed): string
    {
        return $emailed > 0 ? " {$emailed} user(s) are being emailed." : '';
    }
}
