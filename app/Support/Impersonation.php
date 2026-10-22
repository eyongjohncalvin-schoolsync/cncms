<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LandlordActivity;
use App\Models\TenantUserIndex;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Landlord "log in as" a workspace user, for support (web session only —
 * the API and the mobile app never impersonate).
 *
 * The session is simply switched to the target user, with the landlord's id
 * kept in the session under SESSION_KEY so the switch can be undone and so
 * every trail names the real actor:
 *   - landlord_activities: start, stop and every write made meanwhile
 *     (App\Http\Middleware\RecordImpersonatedActions), attributed to the
 *     landlord (LandlordActivity::record() reads impersonatorId()).
 *   - the workspace's own audit_logs: `impersonated_by` on every row
 *     (App\Observers\AuditableObserver), so a landlord's change is never
 *     silently blamed on the staff member.
 *
 * Only landlords impersonate, only workspace members who are not landlords
 * themselves, and never while already impersonating.
 */
class Impersonation
{
    public const string SESSION_KEY = 'impersonator_id';

    public static function impersonatorId(): ?int
    {
        if (! app()->bound('session') || ! request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();

        return $id === null ? null : User::query()->find($id);
    }

    public static function active(): bool
    {
        return self::impersonatorId() !== null;
    }

    public static function start(User $landlord, User $target): void
    {
        if (self::active()) {
            throw new RuntimeException('Stop the current impersonation first.');
        }

        if (! $landlord->is_landlord) {
            throw new RuntimeException('Only landlords can impersonate.');
        }

        if ($target->is($landlord) || $target->is_landlord) {
            throw new RuntimeException('Landlords cannot be impersonated.');
        }

        if ($target->status !== 'active') {
            throw new RuntimeException("{$target->name}'s account is not active.");
        }

        $tenantId = TenantUserIndex::query()->where('user_id', $target->id)->value('tenant_id');

        if ($tenantId === null) {
            throw new RuntimeException("{$target->name} does not belong to any workspace.");
        }

        LandlordActivity::record(
            'impersonation.started',
            "Started impersonating {$target->name} <{$target->email}> in {$tenantId}",
            'user',
            $target->id,
            ['tenant_id' => $tenantId],
            $landlord,
        );

        $session = request()->session();
        $session->regenerate();
        Auth::guard('web')->login($target);
        $session->put(self::SESSION_KEY, $landlord->id);
    }

    /**
     * Switches the session back to the landlord and returns them.
     */
    public static function stop(): User
    {
        $landlord = self::impersonator() ?? throw new RuntimeException('Not impersonating.');
        $target = Auth::user();

        LandlordActivity::record(
            'impersonation.stopped',
            'Stopped impersonating '.($target ? "{$target->name} <{$target->email}>" : 'a user'),
            'user',
            $target?->id,
            null,
            $landlord,
        );

        $session = request()->session();
        $session->forget(self::SESSION_KEY);
        $session->regenerate();
        Auth::guard('web')->login($landlord);

        return $landlord;
    }
}
