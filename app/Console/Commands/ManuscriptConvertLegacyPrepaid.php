<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\FreeMonthGrantService;
use App\Services\ManuscriptRunLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off (owner, 2026-10-26): customers who had paid ahead in the old
 * system carry a "covered until" DATE on their latest bill
 * (`manuscripts.payment_expiration`); every new prepayment is a NUMBER of
 * months (`prepaid_months_remaining`). This turns each date into months, so
 * no dates remain and they bill exactly like any other prepaid customer.
 *
 * Only the month counts: covered until any day of a month = covered through
 * that month. Months to convert = the months after the latest bill up to
 * and including the expiry month. A date whose month is already billed is
 * simply cleared (nothing left). Only the latest bill row's carry-forward
 * fields change — `prepaid_months_remaining`, `prepaid_rate`,
 * `payment_expiration` — never a money column; saved through Eloquent, so
 * the audit log records old and new values. Rows of a closed (locked) month
 * are left alone and reported.
 *
 * Dry run by default; --apply writes. Safe to delete once run.
 */
class ManuscriptConvertLegacyPrepaid extends Command
{
    protected $signature = 'manuscript:convert-legacy-prepaid
        {--tenant=swecom : Tenant id}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = 'One-off: turn old-system prepaid "covered until" dates into a number of prepaid months';

    public function handle(ManuscriptRunLockService $lock): int
    {
        $tenant = Tenant::find((string) $this->option('tenant'));

        if ($tenant === null) {
            $this->error('Tenant "'.$this->option('tenant').'" not found.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        // Already inside this tenant (a test, or a caller that switched): leave it as found.
        $switched = tenant()?->getTenantKey() !== $tenant->getTenantKey();

        if ($switched) {
            tenancy()->initialize($tenant);
        }

        try {
            // Each customer's LATEST bill (by month) — the row the next run carries forward from.
            $latest = Manuscript::query()
                ->whereNotNull('payment_expiration')
                ->whereRaw('manuscripts.period = (select max(m2.period) from manuscripts m2 where m2.customer_id = manuscripts.customer_id)')
                ->get();

            $customers = Customer::query()->withTrashed()->with('zone')->whereIn('id', $latest->pluck('customer_id'))->get()->keyBy('id');
            $rows = [];
            $changes = [];

            foreach ($latest->sortBy('payment_expiration') as $manuscript) {
                $customer = $customers->get($manuscript->customer_id);
                $expiryMonth = $manuscript->payment_expiration->format('Y-m');
                $billedThrough = (string) $manuscript->period;
                $months = max(0, FreeMonthGrantService::monthsBetween($billedThrough, $expiryMonth));
                // A payment still waiting to be counted would set a date again.
                $waiting = Payment::query()->where('customer_id', $manuscript->customer_id)->subscription()
                    ->where('verification_status', 'verified')->whereNull('processed_period')->whereNotNull('expiration_date')->count();

                $action = match (true) {
                    $lock->isPeriodLocked($billedThrough) => 'SKIP: latest bill is a closed month',
                    $waiting > 0 => 'SKIP: has a prepayment not yet counted',
                    $months === 0 => 'clear date (already used)',
                    default => "→ {$months} prepaid month(s)",
                };

                $rows[] = [
                    $customer?->name ?? "#{$manuscript->customer_id}",
                    $customer?->zone?->name ?? '—',
                    $billedThrough,
                    $manuscript->payment_expiration->toDateString(),
                    $months > 0 ? FreeMonthGrant::addMonths($billedThrough, 1).' – '.$expiryMonth : '—',
                    $action,
                ];

                if (! str_starts_with($action, 'SKIP')) {
                    $changes[] = [$manuscript, $months, $customer];
                }
            }

            $this->table(['Customer', 'Zone', 'Latest bill', 'Covered until', 'Months', 'Action'], $rows);
            $this->line(count($changes).' to change, '.(count($rows) - count($changes)).' skipped.');

            if (! $apply) {
                $this->warn('Dry run — nothing saved. Run again with --apply to convert.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($changes): void {
                foreach ($changes as [$manuscript, $months, $customer]) {
                    $manuscript->fill([
                        'prepaid_months_remaining' => (int) $manuscript->prepaid_months_remaining + $months,
                        'prepaid_rate' => $months > 0 ? ($manuscript->prepaid_rate ?? $customer?->bill) : $manuscript->prepaid_rate,
                        'payment_expiration' => null,
                    ])->save();
                }
            });

            $this->info(count($changes).' converted.');

            return self::SUCCESS;
        } finally {
            if ($switched) {
                tenancy()->end();
            }
        }
    }
}
