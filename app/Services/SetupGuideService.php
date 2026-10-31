<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Models\Zone;

/**
 * The dashboard's setup guide for a new company's super/admin: zones →
 * customers → staff → agents on the mobile app → first month's bills. Each
 * step is ticked from the company's own data, never by hand; the guide
 * disappears once every step is done or the admin hides it
 * (`setup_guide_hidden` on the tenant). Must run inside the tenant.
 */
class SetupGuideService
{
    /**
     * @return list<array{key: string, done: bool, href: string}>|null null when there is nothing to show
     */
    public function steps(Tenant $tenant): ?array
    {
        if ($tenant->setup_guide_hidden ?? false) {
            return null;
        }

        $steps = [
            ['key' => 'zones', 'done' => Zone::query()->exists(), 'href' => '/zones'],
            ['key' => 'customers', 'done' => Customer::query()->exists(), 'href' => '/customers'],
            ['key' => 'staff', 'done' => TenantUser::query()->count() > 1, 'href' => '/users'],
            ['key' => 'agent_app', 'done' => $this->someoneUsedTheApp($tenant), 'href' => '/users'],
            ['key' => 'first_month', 'done' => CommandRun::query()->where('status', 'published')->exists(), 'href' => '/settings/command-runs'],
        ];

        return collect($steps)->every(fn (array $step) => $step['done']) ? null : $steps;
    }

    public function hide(Tenant $tenant): void
    {
        $tenant->setup_guide_hidden = true;
        $tenant->save();
    }

    /**
     * Someone in the company has signed in on the mobile app (it signs in
     * with an API token; the website never does).
     */
    private function someoneUsedTheApp(Tenant $tenant): bool
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', TenantUserIndex::query()->where('tenant_id', $tenant->id)->select('user_id'))
            ->exists();
    }
}
