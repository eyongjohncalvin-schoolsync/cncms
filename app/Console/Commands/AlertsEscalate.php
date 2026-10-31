<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ArrearsAdjustment;
use App\Models\Complaint;
use App\Models\Notification;
use App\Models\PaymentReversalRequest;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\ActionAlertService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Escalation (docs/plans/mobile-manager-app.md, wave 2): an inbox item
 * nobody has acted on for too long rings on the owner's phone — once per
 * item. Vouchers after 2 hours (money is waiting), complaints with nobody
 * assigned after 4, arrears adjustments and reversal requests after 24.
 * Skips read-only demos. Scheduled every 30 minutes in routes/console.php.
 */
class AlertsEscalate extends Command
{
    /** @var array<string, int> source type => hours before escalating */
    public const array AFTER_HOURS = ['voucher' => 2, 'complaint' => 4, 'arrears_adjustment' => 24, 'reversal_request' => 24];

    protected $signature = 'alerts:escalate {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Ring the owner about decisions left waiting too long';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenants = ($tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all())
            ->reject(fn (Tenant $tenant) => $tenant->isReadOnly());

        tenancy()->runForMultiple($tenants, function (Tenant $tenant): void {
            $alerts = app(ActionAlertService::class);
            $owners = $alerts->owners();

            if ($owners->isEmpty()) {
                return;
            }

            $sent = 0;

            foreach ($this->overdue() as [$sourceType, $model, $what, $link]) {
                if (Notification::query()->where('type', 'inbox.escalated')->where('source_uuid', $model->uuid)->exists()) {
                    continue;
                }

                $hours = (int) floor($model->created_at->diffInMinutes(now()) / 60);
                $sent += $alerts->alert($owners, 'inbox.escalated', 'urgent', 'Waiting too long', "{$what} has waited {$hours} hours for a decision.", $link, $sourceType, $model->uuid, $model->branch_id);
            }

            $this->line("  [{$tenant->id}] {$sent} escalation alert(s).");
        });

        return self::SUCCESS;
    }

    /**
     * @return iterable<array{0: string, 1: Model, 2: string, 3: string}>
     */
    private function overdue(): iterable
    {
        $before = fn (string $type) => now()->subHours(self::AFTER_HOURS[$type]);

        foreach (Voucher::query()->withoutGlobalScopes()->where('status', 'pending')->where('created_at', '<=', $before('voucher'))->get() as $voucher) {
            yield ['voucher', $voucher, "Voucher {$voucher->number}", "/vouchers/{$voucher->uuid}"];
        }

        foreach (Complaint::query()->withoutGlobalScopes()->where('status', 'open')->whereNull('assigned_to')->whereNull('duplicate_of_id')->where('created_at', '<=', $before('complaint'))->get() as $complaint) {
            yield ['complaint', $complaint, "A complaint with no technician (\"{$complaint->title}\")", "/complaints/{$complaint->uuid}"];
        }

        foreach (ArrearsAdjustment::query()->withoutGlobalScopes()->whereIn('status', ['pending', 'pending_second_approval'])->where('created_at', '<=', $before('arrears_adjustment'))->get() as $adjustment) {
            yield ['arrears_adjustment', $adjustment, 'An arrears adjustment', '/arrears-adjustments'];
        }

        foreach (PaymentReversalRequest::query()->withoutGlobalScopes()->with('payment')->where('status', PaymentReversalRequest::STATUS_PENDING)->where('created_at', '<=', $before('reversal_request'))->get() as $request) {
            yield ['reversal_request', $request, 'A request to reverse a payment', '/payments/reversal-requests'];
        }
    }
}
