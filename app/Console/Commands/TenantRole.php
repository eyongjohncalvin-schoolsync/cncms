<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;

/**
 * Sets a central user's role inside a tenant — creating the `tenant_users`
 * membership row if it doesn't exist.
 *
 *   php artisan cncms:tenant-role swecom you@example.com super
 *
 * Two uses:
 *   1. The Landlord "Add Tenant" flow (Landlord\TenantController::store)
 *      provisions the schema but assigns NO owner — this is how you make
 *      someone the `super` of a landlord-created workspace.
 *   2. Fixing / changing a user's role in a workspace when there's no UI
 *      path (e.g. bootstrapping, or a self-service owner who needs a
 *      second admin before the Settings > Users screen is reachable).
 *
 * Run from the Laravel Cloud command runner.
 */
class TenantRole extends Command
{
    protected $signature = 'cncms:tenant-role
        {tenant : The tenant id / slug (e.g. swecom)}
        {email : The central user\'s email}
        {role : super|admin|manager|agent|worker}';

    protected $description = "Set a user's role in a tenant (creates the membership if missing)";

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->argument('role');

        $validator = validator(
            ['role' => $role],
            ['role' => ['required', Rule::in(['super', 'admin', 'manager', 'agent', 'worker'])]],
        );

        if ($validator->fails()) {
            $this->error('Role must be one of: super, admin, manager, agent, worker.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("No tenant [{$tenantId}].");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $membership = TenantUser::query()->updateOrCreate(
                ['user_id' => $user->id, 'tenant_id' => $tenant->id],
                ['role' => $role],
            );
        } finally {
            tenancy()->end();
        }

        $verb = $membership->wasRecentlyCreated ? 'Added' : 'Updated';
        $this->info("{$verb} {$user->name} <{$email}> as [{$role}] in tenant [{$tenantId}].");

        return self::SUCCESS;
    }
}
