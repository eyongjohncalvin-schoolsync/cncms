<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Zone;
use App\Services\CustomerStatusService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * End-to-end proof for the gap fixed in
 * App\Services\CustomerStatusService::reconnectOne(): business-rules.md
 * section 6 says reconnection requires paying "the outstanding balance plus
 * the reconnection fine", but only the fine half was ever actually
 * implemented — the arrears half was silently missing until now. This test
 * proves the new optional `$arrearsPayment` parameter genuinely reduces the
 * customer's arrears on the very next `manuscript:calculate` run, exactly
 * the way any other verified payment would — not just that a second Payment
 * row gets written.
 *
 * Mirrors tests/Feature/CustomerImportSeedsManuscriptArrearsTest.php's
 * rigor and its real (uncommitted-transaction) writes against the real
 * swecom tenant schema, with the same explicit, non-transactional cleanup
 * in a finally block: DatabaseTransactions only wraps the default `pgsql`
 * connection, and Stancl's tenancy()->end() (required between artisan
 * command runs) purges the dynamically-created `tenant` connection, which
 * would silently roll back any fixtures still sitting in an open
 * transaction on it.
 *
 * CustomerStatusService::reconnect() is called directly (not via the HTTP
 * route already covered by tests/Feature/Web/CustomerTest.php) with a
 * manually-bound TenantContext standing in for the 'manager' role that
 * would normally come from ResolveTenantWeb — this test is about the
 * arrears-to-manuscript pipeline, not the HTTP/auth layer.
 */
class CustomerReconnectArrearsPaymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_partial_arrears_payment_recorded_at_reconnection_reduces_arrears_on_the_next_manuscript_run(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        tenancy()->initialize($tenant);

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2000,
            'others' => 0,
            'status' => 'active',
        ]);

        // Deliberately far in the future — periods like '2026-01'/'2026-02'
        // may already carry real historical command_runs rows (the real
        // system has been running since May 2025), and this test's finally
        // block deletes command_runs by command+period, not scoped to this
        // fixture's customer. A period nobody has run yet avoids any risk
        // of that cleanup deleting real production audit history.
        $period1 = '2031-01';
        $period2 = '2031-02';

        // Stands in for the tenant role a real 'manager' request would carry
        // (see ResolveTenantWeb) — PaymentService::create() only reads
        // ->role off this, never ->tenantUser, so a bare unsaved TenantUser
        // is fine here.
        $this->app->instance(TenantContext::class, new TenantContext(new TenantUser, 'manager'));

        try {
            // Period 1: active, fully unpaid — establishes a plain 2,000
            // FCFA of arrears (one month's unpaid bill) via the real
            // command, exactly like any other customer.
            tenancy()->end();

            $this->artisan('manuscript:calculate', [
                'period' => $period1,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $manuscript1 = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period1)
                ->first();

            $this->assertNotNull($manuscript1, 'expected a manuscript row after the first calculate run');
            $this->assertEqualsWithDelta(2000.0, (float) $manuscript1->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $manuscript1->credit, 0.001);

            // Disconnect the customer — business-rules.md section 6: arrears
            // freeze at 2,000 while disconnected, no new accrual.
            $customer->update(['status' => 'disconnected']);

            // The feature under test: reconnect with the fine (2,000
            // default) PLUS a 1,200 FCFA partial arrears payment.
            app(CustomerStatusService::class)->reconnect($customer->fresh(), null, true, '1200.00');

            $customer->refresh();
            $this->assertSame('active', $customer->status);

            $payments = Payment::query()->where('customer_id', $customer->id)->orderBy('amount')->get();
            $this->assertCount(2, $payments, 'expected exactly the fine payment and the arrears payment as two separate rows');
            $this->assertEqualsWithDelta(1200.0, (float) $payments[0]->amount, 0.001);
            $this->assertEqualsWithDelta(2000.0, (float) $payments[1]->amount, 0.001);
            $this->assertSame('verified', $payments[0]->verification_status);
            $this->assertSame('verified', $payments[1]->verification_status);

            tenancy()->end();

            // Period 2: the real manuscript:calculate command must fold
            // BOTH payments into income like any other verified payment —
            // no special-casing required in ManuscriptCalculator.
            $this->artisan('manuscript:calculate', [
                'period' => $period2,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $manuscript2 = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period2)
                ->first();

            $this->assertNotNull($manuscript2, 'expected a manuscript row after the second calculate run');

            // previousNet = arrears(2000) - credit(0) = 2000
            // income       = fine(2000) + arrears payment(1200) = 3200
            // net          = previousNet + bill(2000) - income(3200) = 800
            // -> total_arrears = 800 (down from 2000 — exactly the 1,200
            //    difference the arrears payment covered above the fine),
            //    credit = 0, total_bill = bill(2000) + (800 - 0) = 2800.
            $this->assertEqualsWithDelta(800.0, (float) $manuscript2->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $manuscript2->credit, 0.001);
            $this->assertEqualsWithDelta(2800.0, (float) $manuscript2->total_bill, 0.001);

            // Both payments were genuinely consumed as income (processed_at
            // stamped) — proof the arrears payment wasn't silently ignored.
            foreach ($payments as $payment) {
                $this->assertNotNull($payment->fresh()->processed_at);
            }
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            Manuscript::query()->where('customer_id', $customer->id)->delete();
            Payment::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->whereIn('period', [$period1, $period2])->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }
}
