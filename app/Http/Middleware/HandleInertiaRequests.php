<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares data with every Inertia response: the authenticated user (central
 * `users` row) plus their resolved tenant role, when App\Http\Middleware\
 * ResolveTenantWeb has already run and bound TenantContext for this
 * request. Mirrors what Api\AuthController::me() exposes over the API, so
 * both frontends see the same shape.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $context?->role,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
