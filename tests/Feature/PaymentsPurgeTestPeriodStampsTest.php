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
 * `payments:purge-test-period-stamps` — scrubs bogus far-future
 * (2031/2033/2034) `processed_period` values left on real payments by test
 * runs. Disposable tenant (the command owns its own tenancy lifecycle).
 */
class PaymentsPurgeTestPeriodStampsTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionDisposableTenant('ptps');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->tenant->delete();
        parent::tearDown();
    }

    private function withinTenancy(callable $cb): mixed
    {
        tenancy()->initialize($this->tenant);
        try {
            return $cb();
        } finally {
            tenancy()->end();
        }
    }

    private function seedPayment(string $createdAt, ?string $processedPeriod): string
    {
        return $this->withinTenancy(function () use ($createdAt, $processedPeriod): string {
            $zone = ZoneFactory::new()->create();
            $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

            return PaymentFactory::new()->create([
                'customer_id' => $customer->id,
                'amount' => 2500,
                'verification_status' => 'verified',
                'processed_period' => $processedPeriod,
                'processed_at' => $processedPeriod !== null ? now() : null,
                'created_at' => $createdAt,
            ])->uuid;
        });
    }

    private function periodOf(string $uuid): ?string
    {
        return $this->withinTenancy(fn (): ?string => Payment::query()->where('uuid', $uuid)->value('processed_period'));
    }

    /** @return array<string, mixed> */
    private function args(bool $apply): array
    {
        return ['--tenant' => $this->tenant->id, '--force' => true, ...($apply ? ['--apply' => true] : [])];
    }

    public function test_dry_run_writes_nothing(): void
    {
        $p = $this->seedPayment('2026-04-10 09:00:00', '2031-01');

        $this->artisan('payments:purge-test-period-stamps', $this->args(false))->assertExitCode(0);

        $this->assertSame('2031-01', $this->periodOf($p));
    }

    public function test_apply_rehomes_bogus_stamps_by_creation_date(): void
    {
        $apr = $this->seedPayment('2026-04-10 09:00:00', '2031-01');   // pre-cutoff -> real month
        $jul = $this->seedPayment('2026-07-28 18:00:00', '2033-02');   // pre-cutoff -> real month
        $aug = $this->seedPayment('2026-08-15 12:00:00', '2031-01');   // on/after cutoff -> NULL
        $real = $this->seedPayment('2026-05-01 08:00:00', '2026-05');  // legit stamp -> untouched
        $null = $this->seedPayment('2026-08-30 08:00:00', null);       // already NULL -> untouched

        $this->artisan('payments:purge-test-period-stamps', $this->args(true))->assertExitCode(0);

        $this->assertSame('2026-04', $this->periodOf($apr));
        $this->assertSame('2026-07', $this->periodOf($jul));
        $this->assertNull($this->periodOf($aug), 'an August payment must go back to NULL for the September run');
        $this->assertSame('2026-05', $this->periodOf($real), 'a legitimate real-month stamp must not be touched');
        $this->assertNull($this->periodOf($null));
    }

    public function test_apply_is_idempotent(): void
    {
        $this->seedPayment('2026-06-10 09:00:00', '2034-01');

        $this->artisan('payments:purge-test-period-stamps', $this->args(true))->assertExitCode(0);
        $this->artisan('payments:purge-test-period-stamps', $this->args(true))
            ->expectsOutputToContain('nothing to do')
            ->assertExitCode(0);
    }
}
