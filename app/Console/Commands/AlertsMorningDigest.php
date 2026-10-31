<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ManagerInboxService;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * The morning summary (docs/plans/mobile-manager-app.md, wave 2): each
 * member whose manager inbox has something waiting gets one quiet phone
 * notification saying how much — worked out with that member's own rules
 * (their TenantContext bound while ManagerInboxService runs). Nothing
 * waiting, nothing sent. Skips read-only demos. Scheduled daily at 07:00
 * Cameroon time in routes/console.php.
 */
class AlertsMorningDigest extends Command
{
    protected $signature = 'alerts:digest {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Send each manager a quiet morning summary of what waits for them';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenants = ($tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all())
            ->reject(fn (Tenant $tenant) => $tenant->isReadOnly());

        tenancy()->runForMultiple($tenants, function (Tenant $tenant): void {
            $sent = 0;
            $previous = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

            try {
                foreach (TenantUser::query()->where('tenant_id', $tenant->id)->get() as $member) {
                    $user = User::query()->whereKey($member->user_id)->where('status', 'active')->first();

                    if ($user === null) {
                        continue;
                    }

                    $context = TenantContext::resolve($member);
                    app()->instance(TenantContext::class, $context);

                    $items = app(ManagerInboxService::class)->items($user, $context);

                    if ($items === []) {
                        continue;
                    }

                    $urgent = count(array_filter($items, fn (array $item) => $item['severity'] === 'now'));
                    $total = count($items);

                    app(NotificationService::class)->broadcastToUser(
                        $user,
                        'inbox.digest',
                        'warning',
                        'Good morning: '.$total.' '.($total === 1 ? 'thing waits' : 'things wait').' for you',
                        $urgent > 0 ? "{$urgent} of them urgent. Open your inbox to decide." : 'Open your inbox to decide.',
                        '/inbox',
                        branchId: null,
                    );
                    $sent++;
                }
            } finally {
                $previous !== null ? app()->instance(TenantContext::class, $previous) : app()->forgetInstance(TenantContext::class);
            }

            $this->line("  [{$tenant->id}] {$sent} morning summar".($sent === 1 ? 'y' : 'ies').'.');
        });

        return self::SUCCESS;
    }
}
