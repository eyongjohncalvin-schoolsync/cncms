<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /api/v1/bills/{customer}/whatsapp-message — the mobile counterpart of
 * the manual (free, no-Twilio) WhatsApp bill reminder already shipped on
 * the web Manuscripts page (App\Services\BillNotificationService via
 * Api\BillController::whatsappMessage()). See
 * .claude/skills/cncms-context/references/bill-notifications.md section 1.
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles.
 */
class BillWhatsappMessageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customerWithManuscript(array $customerAttributes = [], array $manuscriptAttributes = []): Customer
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'phone' => '677440670',
            ...$customerAttributes,
        ]);

        ManuscriptFactory::new()
            ->forPeriod(Carbon::now()->format('Y-m'))
            ->create([
                'customer_id' => $customer->id,
                'bill' => 2500,
                'total_arrears' => 5000,
                'credit' => 0,
                'total_bill' => 7500,
                ...$manuscriptAttributes,
            ]);

        return $customer;
    }

    /**
     * The message must reflect the SAME manuscript figures the bill-print
     * feature shows (ManuscriptService::billData() ultimately reads the
     * same `latestManuscript` relation) — not a separate/divergent
     * calculation. 7,500 here is bill(2500) + arrears(5000) - credit(0),
     * matching business-rules.md's total_bill formula.
     */
    public function test_returns_a_formatted_message_matching_the_customers_real_bill_figures(): void
    {
        CompanyFactory::new()->create(['momo_number' => '676876509/672528022', 'momo_name' => 'SWECOM PLC']);
        $customer = $this->customerWithManuscript(['name' => 'Ashu Peter']);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
        $response->assertJsonPath('data.has_phone', true);
        $response->assertJsonPath('data.available', true);
        $response->assertJsonPath('data.reason', null);

        $message = $response->json('data.message');
        $this->assertStringContainsString('Ashu Peter', $message);
        // total_bill = 7500, formatted with a thousands separator.
        $this->assertStringContainsString('7,500', $message);
        $this->assertStringContainsString('676876509/672528022', $message);
    }

    /**
     * 677440670 is a 9-digit local Cameroon number with no country code, as
     * stored on real legacy customer records — must normalize to the
     * 237-prefixed, digits-only form wa.me requires (no leading '+'/'00').
     */
    public function test_normalizes_a_local_cameroon_phone_number_to_international_format(): void
    {
        CompanyFactory::new()->create();
        $customer = $this->customerWithManuscript(['phone' => '677440670']);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
        $response->assertJsonPath('data.phone', '237677440670');
    }

    /**
     * A phone already stored with formatting noise (spaces/parens/dashes) —
     * per database-schema.md's known data-quality issues — must still
     * normalize correctly.
     */
    public function test_normalizes_a_messily_formatted_phone_number(): void
    {
        CompanyFactory::new()->create();
        $customer = $this->customerWithManuscript(['phone' => '(67) 744-0670']);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
        $response->assertJsonPath('data.phone', '237677440670');
    }

    /**
     * The ~78% no-phone case (bill-notifications.md section 5 /
     * database-schema.md's known issues) must come back as a clear,
     * structured "not available" response the mobile client can key off —
     * never a raw error and never a broken wa.me link.
     */
    public function test_customer_with_no_phone_returns_a_clear_non_error_response(): void
    {
        CompanyFactory::new()->create();
        $customer = $this->customerWithManuscript(['phone' => null]);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
        $response->assertJsonPath('data.has_phone', false);
        $response->assertJsonPath('data.available', false);
        $response->assertJsonPath('data.reason', 'no_phone');
        $response->assertJsonPath('data.phone', null);
    }

    public function test_customer_with_no_manuscript_yet_returns_a_clear_non_error_response(): void
    {
        CompanyFactory::new()->create();
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'phone' => '677440670']);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
        $response->assertJsonPath('data.has_phone', true);
        $response->assertJsonPath('data.available', false);
        $response->assertJsonPath('data.reason', 'no_manuscript');
        $response->assertJsonPath('data.message', null);
    }

    public function test_agent_can_fetch_the_whatsapp_message_same_as_bill_print_access(): void
    {
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertOk();
    }

    public function test_worker_cannot_fetch_the_whatsapp_message(): void
    {
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/whatsapp-message");

        $response->assertStatus(403);
    }
}
