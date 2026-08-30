<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Message;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\BillNotificationService;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Manual (free, no-Twilio) WhatsApp "Send Bill" flow —
 * .ai/skills/cncms/cncms-context/references/bill-notifications.md sections
 * 1-2 and 6.2. Exercises App\Services\BillNotificationService via
 * ManuscriptController::index()'s wa_link column and
 * ManuscriptController::sendBill()'s messages logging.
 */
class BillNotificationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_manuscripts_index_exposes_a_wa_link_for_a_customer_with_a_phone(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        // CustomerFactory gives every customer its own fresh Zone by
        // default — filtering by that zone's uuid isolates this one row
        // from the ~520 real seeded manuscripts sharing the same period,
        // which would otherwise push this customer (highest customer_id,
        // ordered ascending by ManuscriptRepository::paginate()) off the
        // first page.
        $customer = CustomerFactory::new()->active()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'total_bill' => 5000]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$customer->zone->uuid);

        $response->assertOk();
        $rows = $response->inertiaProps('manuscripts')['data'];
        $row = collect($rows)->firstWhere('customer_uuid', $customer->uuid);

        $this->assertNotNull($row, 'The seeded customer should appear in the current period listing.');
        $this->assertNotNull($row['wa_link']);
        // 677440670 (9 local digits) normalizes to the 237-prefixed form
        // wa.me requires — see BillNotificationService::normalizePhoneForWhatsapp().
        $this->assertStringStartsWith('https://wa.me/237677440670?text=', $row['wa_link']);
    }

    public function test_manuscripts_index_wa_link_is_null_for_a_customer_with_no_phone(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->active()->create(['phone' => null]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$customer->zone->uuid);

        $rows = $response->inertiaProps('manuscripts')['data'];
        $row = collect($rows)->firstWhere('customer_uuid', $customer->uuid);

        $this->assertNotNull($row);
        $this->assertNull($row['wa_link'], 'No phone on file must not produce a broken/dead wa.me link.');
    }

    public function test_send_bill_records_a_messages_row_honest_about_what_it_actually_knows(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->active()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'total_bill' => 7500]);

        $this->actingAsRole('agent');

        $response = $this->post("/manuscripts/{$customer->uuid}/send-bill");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 'link_opened', not 'sent'/'delivered': a human clicking a wa.me
        // link is outside this system's visibility — see
        // ManuscriptController::sendBill()'s doc comment.
        $this->assertDatabaseHas('messages', [
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'link_opened',
            'type' => 'bill_reminder',
        ]);

        $message = Message::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringContainsString($customer->name, $message->content);
        $this->assertStringContainsString('7,500', $message->content);
    }

    public function test_send_bill_fails_gracefully_when_the_customer_has_no_manuscript_yet(): void
    {
        $customer = CustomerFactory::new()->active()->create();

        $this->actingAsRole('agent');

        $response = $this->post("/manuscripts/{$customer->uuid}/send-bill");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('messages', ['customer_id' => $customer->id]);
    }

    /**
     * A bill reminder is only ever sent to an ACTIVE customer (owner
     * decision, 2026-08) — mirrors ManuscriptService::billData()'s refusal
     * for the printed slip. The single guard lives in
     * BillNotificationService::composeMessage(), so waLink() (and therefore
     * the Manuscripts/Index wa_link column and the mobile API) inherit it —
     * even when the customer has both a phone and a manuscript.
     */
    public function test_compose_message_and_wa_link_are_null_for_a_non_active_customer(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $service = app(BillNotificationService::class);

        $active = CustomerFactory::new()->active()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $active->id, 'total_bill' => 5000]);

        $disconnected = CustomerFactory::new()->disconnected()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $disconnected->id, 'total_bill' => 5000]);

        $this->assertNotNull($service->composeMessage($active->fresh()));
        $this->assertStringStartsWith('https://wa.me/237677440670?text=', (string) $service->waLink($active->fresh()));

        $this->assertNull($service->composeMessage($disconnected->fresh()), 'Bills are only sent to active customers.');
        $this->assertNull($service->waLink($disconnected->fresh()), 'A non-active customer must not get a live wa.me link.');
    }

    public function test_manuscripts_index_wa_link_is_null_for_a_non_active_customer_with_a_phone(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->disconnected()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'total_bill' => 5000]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$customer->zone->uuid);

        $rows = $response->inertiaProps('manuscripts')['data'];
        $row = collect($rows)->firstWhere('customer_uuid', $customer->uuid);

        $this->assertNotNull($row);
        $this->assertNull($row['wa_link'], 'A non-active customer must not get a live wa.me link even with a phone on file.');
        $this->assertSame('disconnected', $row['status']);
    }

    public function test_send_bill_is_refused_for_a_non_active_customer_without_logging_a_messages_row(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->disconnected()->create(['phone' => '677440670']);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'total_bill' => 7500]);

        $this->actingAsRole('agent');

        $response = $this->post("/manuscripts/{$customer->uuid}/send-bill");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('messages', ['customer_id' => $customer->id]);
    }

    public function test_worker_role_cannot_view_manuscripts_or_send_a_bill(): void
    {
        $customer = CustomerFactory::new()->create(['phone' => '677440670']);

        $this->actingAsRole('worker');

        $this->get('/manuscripts')->assertStatus(403);
        $this->post("/manuscripts/{$customer->uuid}/send-bill")->assertStatus(403);
    }
}
