<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every workspace user across all tenants, searchable, with "Log in as"
 * (App\Support\Impersonation) — the main way into impersonation, so a
 * landlord helping someone doesn't have to know which workspace they're in.
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
            ->select(['users.id', 'users.uuid', 'users.name', 'users.email', 'users.username', 'users.status', 'users.is_landlord', 'tenant_user_index.tenant_id', 'tenant_user_index.role'])
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
                ])->values(),
                'links' => $users->linkCollection(),
                'total' => $users->total(),
            ],
            'filters' => ['search' => $search],
        ]);
    }
}
