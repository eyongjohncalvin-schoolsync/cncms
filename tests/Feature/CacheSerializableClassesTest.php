<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\NotificationSetting;
use App\Models\Payment;
use App\Models\PaymentVerification;
use Database\Factories\BranchFactory;
use Database\Factories\PaymentVerificationFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The real CACHE_STORE is 'database', which unserializes with
 * `allowed_classes` = config('cache.serializable_classes'). Any class missing
 * from that list comes back as __PHP_Incomplete_Class on the SECOND (cache
 * hit) request — invisible under the tests' non-serializing array store. So
 * these tests round-trip each cached shape through that exact allow-list.
 */
class CacheSerializableClassesTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Cache::forget(NotificationSetting::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(NotificationSetting::CACHE_KEY);

        parent::tearDown();
    }

    private function assertSurvivesCacheRoundTrip(mixed $value): mixed
    {
        $restored = unserialize(serialize($value), [
            'allowed_classes' => config('cache.serializable_classes'),
        ]);

        $this->assertStringNotContainsString('__PHP_Incomplete_Class', serialize($restored));

        return $restored;
    }

    public function test_notification_settings_survive_a_cache_round_trip(): void
    {
        $restored = $this->assertSurvivesCacheRoundTrip(NotificationSetting::cached());

        $this->assertInstanceOf(NotificationSetting::class, $restored);
    }

    public function test_a_zone_with_its_branch_survives_a_cache_round_trip(): void
    {
        $zone = ZoneFactory::new()->create(['branch_id' => BranchFactory::new()->create()->id])->load('branch');

        $restored = $this->assertSurvivesCacheRoundTrip($zone);

        $this->assertInstanceOf(Branch::class, $restored->branch);
    }

    public function test_a_payment_with_its_verification_survives_a_cache_round_trip(): void
    {
        $verification = PaymentVerificationFactory::new()->create();
        $payment = Payment::query()->with(['customer', 'verification.verifier'])->findOrFail($verification->payment_id);

        $restored = $this->assertSurvivesCacheRoundTrip($payment);

        $this->assertInstanceOf(PaymentVerification::class, $restored->verification);
    }
}
