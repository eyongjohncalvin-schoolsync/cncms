<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordActivity;
use App\Models\LoginFailure;
use App\Models\Tenant;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Services\LoginGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landlord → Security: refused password sign-ins across the platform
 * (App\Services\LoginGuard) — the last 24 hours at a glance, accounts
 * blocked right now with an Unblock button, the busiest IP addresses, and
 * the latest failures with the company each account belongs to.
 */
class SecurityController extends Controller
{
    public function __construct(private readonly LoginGuard $guard) {}

    public function index(Request $request): Response
    {
        $since = now()->subDay();
        $recent = LoginFailure::query()->with('user:id,uuid,name,username')->latest('id')->limit(150)->get();
        $blocked = $this->guard->blockedAccounts();
        $companies = $this->companiesFor(collect($blocked)->pluck('user.id')->merge($recent->pluck('user_id'))->filter()->unique());

        $person = fn (?User $user, string $identifier): array => [
            'identifier' => $identifier,
            'user_uuid' => $user?->uuid,
            'user' => $user?->name,
            'company' => $user ? ($companies[$user->id] ?? null) : null,
        ];

        return Inertia::render('Landlord/Security/Index', [
            'summary' => [
                'failures' => LoginFailure::query()->where('reason', LoginFailure::WRONG_PASSWORD)->where('created_at', '>=', $since)->count(),
                'refused_while_blocked' => LoginFailure::query()->where('reason', LoginFailure::WHILE_BLOCKED)->where('created_at', '>=', $since)->count(),
                'ips' => LoginFailure::query()->where('created_at', '>=', $since)->distinct()->count('ip_address'),
                'blocked_now' => count($blocked),
            ],
            'blocked_devices' => collect($this->guard->blockedDevices())->map(fn (array $row) => [
                ...$row,
                'until' => $row['until']->toIso8601String(),
            ])->values(),
            'blocked' => collect($blocked)->map(fn (array $row) => [
                ...$person($row['user'], $row['identifier']),
                'failures' => $row['failures'],
                'until' => $row['until']->toIso8601String(),
            ])->values(),
            'ips' => DB::connection('pgsql')->table('login_failures')
                ->where('created_at', '>=', $since)
                ->whereNotNull('ip_address')
                ->groupBy('ip_address')
                ->orderByDesc('attempts')
                ->limit(10)
                ->get(['ip_address', DB::raw('count(*) as attempts'), DB::raw('count(distinct identifier) as accounts'), DB::raw('max(created_at) as last_at')])
                ->map(fn (object $row) => [
                    'ip_address' => $row->ip_address,
                    'attempts' => (int) $row->attempts,
                    'accounts' => (int) $row->accounts,
                    'last_at' => Carbon::parse($row->last_at)->toIso8601String(),
                ]),
            'recent' => $recent->map(fn (LoginFailure $failure) => [
                'id' => $failure->id,
                ...$person($failure->user, $failure->identifier),
                'reason' => $failure->reason,
                'channel' => $failure->channel,
                'ip_address' => $failure->ip_address,
                'user_agent' => $failure->user_agent,
                'at' => $failure->created_at->toIso8601String(),
                'cleared' => $failure->cleared_at !== null,
            ]),
            'limits' => config('rate-limits.login_block'),
            'device_block_enabled' => $this->guard->deviceBlockEnabled(),
            // So the landlord can check the app sees real addresses (not a
            // proxy's), and find their own to add to LOGIN_TRUSTED_IPS.
            'your_ip' => $request->ip(),
        ]);
    }

    public function unblock(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_uuid' => ['nullable', 'string'],
            'identifier' => ['required', 'string', 'max:191'],
        ]);

        $user = filled($validated['user_uuid'] ?? null) ? User::query()->where('uuid', $validated['user_uuid'])->firstOrFail() : null;
        $cleared = $this->guard->clear($user, $validated['identifier']);
        $who = $user?->name ?? $validated['identifier'];

        LandlordActivity::record('security.unblocked', "Unblocked password sign-in for {$who}", $user ? 'user' : null, $user?->uuid, ['identifier' => $validated['identifier'], 'cleared' => $cleared]);

        return back()->with('success', "{$who} can sign in with a password again.");
    }

    /**
     * Turns the device block on or off for the whole platform — off at busy
     * times when many people share one network, on again afterwards.
     */
    public function deviceBlock(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $this->guard->setDeviceBlock($enabled, $request->user());

        LandlordActivity::record(
            $enabled ? 'security.device_block_on' : 'security.device_block_off',
            $enabled ? 'Turned the sign-in device block on' : 'Turned the sign-in device block off',
        );

        return back()->with('success', $enabled
            ? 'Device block is on. Every address starts from zero.'
            : 'Device block is off. Blocked addresses are released; account blocks still apply.');
    }

    public function unblockDevice(Request $request): RedirectResponse
    {
        $ip = $request->validate(['ip_address' => ['required', 'ip']])['ip_address'];
        $cleared = $this->guard->clearDevice($ip);

        LandlordActivity::record('security.device_unblocked', "Unblocked password sign-in from {$ip}", null, null, ['ip_address' => $ip, 'cleared' => $cleared]);

        return back()->with('success', "Sign-in from {$ip} is allowed again.");
    }

    /**
     * user id => company name.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, string>
     */
    private function companiesFor(Collection $userIds): Collection
    {
        $index = TenantUserIndex::query()->whereIn('user_id', $userIds)->get(['user_id', 'tenant_id']);
        $names = Tenant::query()->whereIn('id', $index->pluck('tenant_id')->unique())->get()->mapWithKeys(fn (Tenant $tenant) => [$tenant->id => (string) $tenant->name]);

        return $index->mapWithKeys(fn (TenantUserIndex $row) => [$row->user_id => $names[$row->tenant_id] ?? $row->tenant_id]);
    }
}
