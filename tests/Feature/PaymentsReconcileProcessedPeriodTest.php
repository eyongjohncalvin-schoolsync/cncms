<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Tenant;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * `payments:reconcile-processed-period` — one-off backfill so the v2
 * `manuscript:calculate 2026-09` run cannot re-consume payments the v1->v2
 * import left with `processed_period = NULL` (v1 consumed each payment once,
 * by calendar month). Disposable tenant, mirroring
 * SwecomRepair202608IncidentTest: the command commits real updates and does
 * its own tenancy()->initialize()/end(), so seeding and assertions are
 * wrapped in withinTenancy() and the command call runs outside tenancy.
 */
class PaymentsReconcileProcessedPeriodTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('prcp');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        parent::tearDown();
    }

    private function withinTenancy(callable $callback): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $callback();
        } finally {
            tenancy()->end();
        }
    }

    private function seedPayment(string $createdAt, string $status = 'verified', ?string $processedPeriod = null): string
    {
        return $this->withinTenancy(function () use ($createdAt, $status, $processedPeriod): string {
            $zone = ZoneFactory::new()->create();
            $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

            return PaymentFactory::new()->create([
                'customer_id' => $customer->id,
                'amount' => 2500,
                'verification_status' => $status,
                'processed_period' => $processedPeriod,
                'processed_at' => $processedPeriod !== null ? now() : null,
                'created_at' => $createdAt,
            ])->uuid;
        });
    }

    private function processedPeriodOf(string $uuid): ?string
    {
        return $this->withinTenancy(fn (): ?string => Payment::query()->where('uuid', $uuid)->value('processed_period'));
    }

    /** @return array{args: array<string, mixed>} */
    private function runArgs(bool $apply): array
    {
        return ['--tenant' => $this->tenant->id, '--force' => true, ...($apply ? ['--apply' => true] : [])];
    }

    public function test_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $apr = $this->seedPayment('2026-04-10 09:00:00');

        $this->artisan('payments:reconcile-processed-period', $this->runArgs(false))->assertExitCode(0);

        $this->assertNull($this->processedPeriodOf($apr));
    }

    public function test_apply_stamps_pre_cutoff_verified_payments_to_their_own_month(): void
    {
        $apr = $this->seedPayment('2026-04-10 09:00:00');
        $jul = $this->seedPayment('2026-07-28 18:00:00');
        $aug = $this->seedPayment('2026-08-15 12:00:00');
        $sep = $this->seedPayment('2026-09-02 08:00:00');                       // after cutoff — leave NULL
        $pending = $this->seedPayment('2026-05-01 08:00:00', 'pending');         // not verified — leave NULL
        $already = $this->seedPayment('2026-03-01 08:00:00', 'verified', '2026-03'); // already stamped — untouched

        $this->artisan('payments:reconcile-processed-period', $this->runArgs(true))->assertExitCode(0);

        $this->assertSame('2026-04', $this->processedPeriodOf($apr));
        $this->assertSame('2026-07', $this->processedPeriodOf($jul));
        $this->assertSame('2026-08', $this->processedPeriodOf($aug));

        $this->assertNull($this->processedPeriodOf($sep), 'a September payment must stay eligible for the September run');
        $this->assertNull($this->processedPeriodOf($pending), 'an unverified payment must not be stamped');
        $this->assertSame('2026-03', $this->processedPeriodOf($already), 'an already-stamped payment must not be re-stamped');

        $this->withinTenancy(function () use ($apr): void {
            $this->assertSame('2026-04', Payment::query()->where('uuid', $apr)->value('processed_at')?->format('Y-m'));
        });
    }

    public function test_apply_is_idempotent(): void
    {
        $jun = $this->seedPayment('2026-06-10 09:00:00');

        $this->artisan('payments:reconcile-processed-period', $this->runArgs(true))->assertExitCode(0);
        $this->assertSame('2026-06', $this->processedPeriodOf($jun));

        $this->artisan('payments:reconcile-processed-period', $this->runArgs(true))
            ->expectsOutputToContain('nothing to do')
            ->assertExitCode(0);
    }

    public function test_refuses_a_non_default_tenant_without_force(): void
    {
        $this->artisan('payments:reconcile-processed-period', ['--tenant' => $this->tenant->id])
            ->assertExitCode(1);
    }
}
