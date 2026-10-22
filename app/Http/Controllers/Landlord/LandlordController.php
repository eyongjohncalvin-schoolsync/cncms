<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantLandlordRequest;
use App\Models\LandlordActivity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has platform landlord access (`users.is_landlord`), with grant and
 * revoke — the UI counterpart of `php artisan cncms:grant-landlord`, which
 * stays for bootstrapping the very first landlord on a fresh server.
 *
 * Granting is only for an existing user (found by email). Nobody can revoke
 * their own access, which also means the platform can never be left without
 * a landlord: whoever revokes is one, and stays one.
 */
class LandlordController extends Controller
{
    public function index(): Response
    {
        $landlords = User::query()
            ->where('is_landlord', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email', 'landlord_granted_by', 'landlord_granted_at']);

        $grantors = User::query()->whereIn('id', $landlords->pluck('landlord_granted_by')->filter())->pluck('name', 'id');

        return Inertia::render('Landlord/Landlords/Index', [
            'landlords' => $landlords->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'granted_by' => $user->landlord_granted_by ? ($grantors[$user->landlord_granted_by] ?? null) : null,
                'granted_at' => $user->landlord_granted_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(GrantLandlordRequest $request): RedirectResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->firstOrFail();

        if ($user->is_landlord) {
            return back()->with('error', "{$user->name} is already a landlord.");
        }

        $user->forceFill([
            'is_landlord' => true,
            'landlord_granted_by' => $request->user()->id,
            'landlord_granted_at' => now(),
        ])->save();

        LandlordActivity::record('landlord.granted', "Granted landlord access to {$user->name} <{$user->email}>", 'user', $user->id);

        return back()->with('success', "{$user->name} is now a landlord.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->is_landlord, 404);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot revoke your own landlord access.');
        }

        $user->forceFill([
            'is_landlord' => false,
            'landlord_granted_by' => null,
            'landlord_granted_at' => null,
        ])->save();

        LandlordActivity::record('landlord.revoked', "Revoked landlord access from {$user->name} <{$user->email}>", 'user', $user->id);

        return back()->with('success', "{$user->name} is no longer a landlord.");
    }
}
