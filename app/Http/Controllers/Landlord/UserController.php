<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetTwoFactorRequest;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every workspace user across all tenants, searchable, with "Log in as"
 * (App\Support\Impersonation) — the main way into impersonation, so a
 * landlord helping someone doesn't have to know which workspace they're in —
 * and "Reset 2FA" for someone locked out of their authenticator.
 * Central data only: the membership index + users.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->join('tenant_user_index', 'tenant_user_index.user_id', '=', 'users.id')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(users.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.username) LIKE ?', [$like]));
            })
            ->orderBy('users.name')
            ->select(['users.id', 'users.uuid', 'users.name', 'users.email', 'users.username', 'users.status', 'users.is_landlord', 'users.two_factor_secret', 'users.two_factor_confirmed_at', 'tenant_user_index.tenant_id', 'tenant_user_index.role'])
            ->paginate(50)
            ->withQueryString();

        $tenantNames = Tenant::query()
            ->whereIn('id', $users->getCollection()->pluck('tenant_id')->unique())
            ->get()
            ->mapWithKeys(fn (Tenant $tenant) => [$tenant->id => $tenant->name]);

        return Inertia::render('Landlord/Users/Index', [
            'users' => [
                'data' => $users->getCollection()->map(fn (User $user) => [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'workspace' => $tenantNames[$user->getAttribute('tenant_id')] ?? $user->getAttribute('tenant_id'),
                    'role' => $user->getAttribute('role'),
                    'status' => $user->status,
                    'can_impersonate' => $user->status === 'active' && ! $user->is_landlord,
                    'can_reset_two_factor' => $user->hasTwoFactorEnabled() && $user->id !== $request->user()->id,
                ])->values(),
                'links' => $users->linkCollection(),
                'total' => $users->total(),
            ],
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * "Reset 2FA" for a user locked out of their authenticator app: clears
     * their secret and recovery codes, signs them out everywhere and emails
     * them (TwoFactorService::resetBySupport()). Landlord only, never on
     * yourself (use My account), never while impersonating (route
     * middleware), and always with a reason, kept in the activity log.
     */
    public function resetTwoFactor(ResetTwoFactorRequest $request, User $user, TwoFactorService $twoFactor): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot reset your own two-factor here. Use My account.');
        }

        if (! $user->hasTwoFactorEnabled()) {
            return back()->with('error', "{$user->name} does not have two-factor turned on.");
        }

        $twoFactor->resetBySupport($user);

        $reason = trim($request->validated('reason'));

        LandlordActivity::record(
            'user.two_factor_reset',
            "Reset two-factor for {$user->name} <{$user->email}>",
            'user',
            $user->id,
            ['reason' => $reason],
        );

        return back()->with('success', "Two-factor reset for {$user->name}. They have been signed out everywhere and emailed.");
    }
}
