<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentReceipt;
use App\Models\Tenant;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * `cncms:backfill-payment-receipts` — Wave 1 of
 * docs/plans/payment-receipts-and-whatsapp.md.
 *
 * Uses a disposable tenant SCHEMA (not DatabaseTransactions): the command
 * runs `tenancy()->runForMultiple`, which re-initializes tenancy and would
 * purge/roll back an outer test transaction (see
 * Tests\Feature\Concerns\UsesDisposableTenant's class doc). tearDown drops
 * the whole schema, so nothing here can ever touch `swecom`.
 */
class PaymentReceiptBackfillTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zrcp');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        parent::tearDown();
    }

    private function seedPayments(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active', 'bill' => 2500]);

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);          // verified
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 5000]);          // verified
        PaymentFactory::new()->rejected()->create(['customer_id' => $customer->id, 'amount' => 1]); // rejected
        PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 1]);  // pending
    }

    public function test_dry_run_reports_the_count_and_writes_nothing(): void
    {
        $this->seedPayments();

        $this->artisan('cncms:backfill-payment-receipts', ['tenant' => $this->tenant->id])
            ->expectsOutputToContain('2 receipt(s) would be issued')
            ->assertSuccessful();

        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_no_dry_run_issues_receipts_only_for_verified_payments_and_is_idempotent(): void
    {
        $this->seedPayments();

        $this->artisan('cncms:backfill-payment-receipts', ['tenant' => $this->tenant->id, '--no-dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(2, PaymentReceipt::query()->count());
        $this->assertSame(2, PaymentReceipt::query()->issued()->count());

        // Re-run: nothing new.
        $this->artisan('cncms:backfill-payment-receipts', ['tenant' => $this->tenant->id, '--no-dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(2, PaymentReceipt::query()->count());
    }
}
