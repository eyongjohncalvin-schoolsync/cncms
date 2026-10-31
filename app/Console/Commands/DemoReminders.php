<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\DemoReminderMail;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * The free demo's reminder emails (Tenant::isOnDemo): 30, 7 and 1 day(s)
 * before its last day, then "ended, read-only" the day after — to the
 * company's super/admin users, and the "ended" one to every landlord too.
 * Each stage goes once (`demo_reminders_sent`); a missed day sends only the
 * latest stage due. Runs daily, scheduled in routes/console.php.
 */
class DemoReminders extends Command
{
    /**
     * [stage, due when days left <=], latest first: the first one due is
     * the one sent, and it marks every stage after it in this list as sent.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const array STAGES = [['ended', -1], ['1', 1], ['7', 7], ['30', 30]];

    protected $signature = 'demo:reminders {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Email free-demo reminders (30/7/1 days, then ended) to company admins';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');

        $tenants = ($tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all())
            ->filter(fn (Tenant $tenant) => $tenant->isOnDemo() && $tenant->isApproved() && $tenant->is_active);

        foreach ($tenants as $tenant) {
            $daysLeft = (int) $tenant->demoDaysLeft();
            $sent = $tenant->demo_reminders_sent ?? [];
            $index = collect(self::STAGES)->search(fn (array $stage) => $daysLeft <= $stage[1]);

            if ($index === false || in_array(self::STAGES[$index][0], $sent, true)) {
                continue;
            }

            $stage = self::STAGES[$index][0];

            $admins = $tenant->run(fn () => $this->admins($tenant));

            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(new DemoReminderMail($stage, (string) $tenant->name, (string) $tenant->demo_ends_on, $admin->name));
            }

            if ($stage === 'ended') {
                User::query()->where('is_landlord', true)->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')
                    ->each(fn (User $landlord) => Mail::to($landlord->email)->queue(
                        new DemoReminderMail($stage, (string) $tenant->name, (string) $tenant->demo_ends_on, $landlord->name, forLandlord: true),
                    ));

                LandlordActivity::record('tenant.demo_ended', "{$tenant->name}'s free demo ended; the workspace is read-only", 'tenant', $tenant->id);
            }

            // This stage and every earlier one count as sent.
            $tenant->demo_reminders_sent = array_values(array_unique([...$sent, ...array_column(array_slice(self::STAGES, $index), 0)]));
            $tenant->save();

            $this->line("  [{$tenant->id}] sent the '{$stage}' reminder to {$admins->count()} admin(s).");
        }

        return self::SUCCESS;
    }

    /**
     * The company's active super/admin users with an email.
     *
     * @return Collection<int, User>
     */
    private function admins(Tenant $tenant): Collection
    {
        $userIds = TenantUser::query()->where('tenant_id', $tenant->id)->whereIn('role', ['super', 'admin'])->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')->get(['id', 'name', 'email']);
    }
}
