<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Tenant;
use App\Services\PaymentReceiptService;
use Illuminate\Console\Command;

/**
 * Issues a payment receipt for every already-`verified` payment that
 * predates the receipts feature (docs/plans/payment-receipts-and-whatsapp.md,
 * Wave 1). Auto-issue on verify() only covers payments verified from now on.
 *
 *   php artisan cncms:backfill-payment-receipts             # dry run, every tenant
 *   php artisan cncms:backfill-payment-receipts swecom      # dry run, one tenant
 *   php artisan cncms:backfill-payment-receipts swecom --no-dry-run
 *
 * Dry run is the DEFAULT — it writes nothing and just reports the count.
 * Pass --no-dry-run (or --force) to actually issue. Idempotent: only
 * payments with no `payment_receipts` row are touched, so a re-run after a
 * partial run just finishes the remainder. Chunked; safe on live
 * `tenantswecom`.
 */
class BackfillPaymentReceipts extends Command
{
    protected $signature = 'cncms:backfill-payment-receipts
        {tenant? : Tenant id / slug; omit to process every tenant}
        {--dry-run : Report only, write nothing (this is the default)}
        {--no-dry-run : Actually issue the receipts}
        {--force : Alias for --no-dry-run}';

    protected $description = 'Issue payment receipts for existing verified payments that do not have one yet';

    public function handle(PaymentReceiptService $receipts): int
    {
        $write = $this->option('no-dry-run') || $this->option('force');

        $tenantId = $this->argument('tenant');
        $tenants = $tenantId !== null
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error($tenantId !== null ? "No tenant [{$tenantId}]." : 'No tenants found.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Payment receipt backfill</info>  '.($write ? '<comment>MODE: WRITE</comment>' : 'MODE: dry run (pass --no-dry-run to write)'));

        $grandIssued = 0;
        $grandPending = 0;

        tenancy()->runForMultiple($tenants, function (Tenant $tenant) use ($receipts, $write, &$grandIssued, &$grandPending): void {
            $base = fn () => Payment::query()
                ->where('verification_status', 'verified')
                ->whereNotExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('payment_receipts')
                    ->whereColumn('payment_receipts.payment_id', 'payments.id'));

            $count = $base()->count();
            $grandPending += $count;

            if ($count === 0) {
                $this->line("  [{$tenant->id}] nothing to backfill.");

                return;
            }

            if (! $write) {
                $this->line("  [{$tenant->id}] <info>{$count}</info> verified payment(s) would get a receipt.");

                return;
            }

            $issued = 0;
            $base()->orderBy('id')->chunkById(200, function ($payments) use ($receipts, &$issued): void {
                foreach ($payments as $payment) {
                    $receipts->issueFor($payment);
                    $issued++;
                }
            });

            $grandIssued += $issued;
            $this->line("  [{$tenant->id}] issued <info>{$issued}</info> receipt(s). Total now: ".PaymentReceipt::query()->count());
        });

        $this->newLine();
        $this->info($write
            ? "Done. Issued {$grandIssued} receipt(s) across ".$tenants->count().' tenant(s).'
            : "Dry run complete. {$grandPending} receipt(s) would be issued. Re-run with --no-dry-run to write.");

        return self::SUCCESS;
    }
}
