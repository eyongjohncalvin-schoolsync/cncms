<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\CashAlertsDigestMail;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails each tenant's open cash alerts (App\Services\CashAlertService) to
 * everyone who can see the cash book (`cash.view`, the owner included) and
 * to the investors — every morning, and only when something is open
 * (docs/plans/cash-controls.md, wave 6). Scheduled in routes/console.php.
 */
class CashAlertsDigest extends Command
{
    protected $signature = 'cash:alerts-digest {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Email the open cash-control alerts to managers, the owner and the investors';

    public function handle(CashAlertService $alerts): int
    {
        $tenantId = $this->argument('tenant');
        $tenants = $tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all();

        tenancy()->runForMultiple($tenants, function (Tenant $tenant) use ($alerts): void {
            $open = $alerts->alerts();

            if ($open === []) {
                $this->line("  [{$tenant->id}] nothing to report.");

                return;
            }

            $roles = Role::query()
                ->where('is_super', true)
                ->orWhereHas('permissions', fn ($query) => $query->where('permission', 'cash.view'))
                ->pluck('name');

            $userIds = TenantUser::query()
                ->where('tenant_id', $tenant->id)
                ->where(fn ($query) => $query->whereIn('role', $roles)->orWhere('is_investor', true))
                ->pluck('user_id');

            $recipients = User::query()->whereIn('id', $userIds)->whereNotNull('email')->get();

            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->queue(new CashAlertsDigestMail($open, $recipient->name));
            }

            $this->line("  [{$tenant->id}] ".count($open)." alert group(s) sent to {$recipients->count()} recipient(s).");
        });

        return self::SUCCESS;
    }
}
