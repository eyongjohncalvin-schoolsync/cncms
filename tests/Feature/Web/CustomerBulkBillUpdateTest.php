<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The bulk bill-rate-update tool on the Customers list (App\Http\
 * Controllers\CustomerController::bulkUpdateBill()/previewBulkUpdateBill(),
 * App\Services\CustomerService's matching methods) — an annual
 * price-adjustment workflow office staff use instead of editing each
 * customer's bill one at a time. Same InteractsWithTenantRoles tenant/
 * transaction setup as the other Web bulk-action tests (DisconnectionsTest,
 * etc.) this complements.
 */
class CustomerBulkBillUpdateTest extends TestCase
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

    public function test_manager_can_bulk_update_bills_by_explicit_uuids_with_set_mode(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 3000]);

        $response = $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'mode' => 'set',
            'value' => '5000.00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $customerA->id, 'bill' => 5000]);
        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'bill' => 5000]);
    }

    public function test_bulk_update_by_explicit_uuids_with_increase_fixed_mode(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 3000]);

        $response = $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'mode' => 'increase_fixed',
            'value' => '500',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $customerA->id, 'bill' => 3000]);
        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'bill' => 3500]);
    }

    public function test_bulk_update_by_explicit_uuids_with_increase_percent_mode(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2000]);

        $response = $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'increase_percent',
            'value' => '10',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        // 2000 + 10% = 2200.00
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 2200]);
    }

    public function test_bulk_update_by_filter_descriptor_updates_every_customer_in_the_zone(): void
    {
        $this->actingAsRole('manager');

        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        $inZone = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'bill' => 2500]);
        $alsoInZone = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'bill' => 3000]);
        $otherZone = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'bill' => 2500]);

        // No customer_uuids at all — selection is entirely by the filter
        // descriptor, exactly the "select by filter, not by uuid list"
        // scaling path the bulk tool exists for.
        $response = $this->post('/customers/bulk-update-bill', [
            'zone_uuid' => $zoneA->uuid,
            'mode' => 'increase_fixed',
            'value' => '500',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $inZone->id, 'bill' => 3000]);
        $this->assertDatabaseHas('customers', ['id' => $alsoInZone->id, 'bill' => 3500]);
        // Untouched — outside the filtered zone.
        $this->assertDatabaseHas('customers', ['id' => $otherZone->id, 'bill' => 2500]);
    }

    public function test_bulk_update_by_filter_descriptor_scoped_to_level(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $vip = CustomerFactory::new()->create(['zone_id' => $zone->id, 'level' => 'Vip', 'bill' => 3000]);
        $normal = CustomerFactory::new()->create(['zone_id' => $zone->id, 'level' => 'normal', 'bill' => 2500]);

        $response = $this->post('/customers/bulk-update-bill', [
            'level' => 'Vip',
            'mode' => 'set',
            'value' => '5000',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $vip->id, 'bill' => 5000]);
        $this->assertDatabaseHas('customers', ['id' => $normal->id, 'bill' => 2500]);
    }

    public function test_preview_returns_correct_values_without_persisting_anything(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'name' => 'Preview Only Customer']);

        $response = $this->postJson('/customers/bulk-update-bill/preview', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'increase_fixed',
            'value' => '500',
        ]);

        $response->assertOk();
        $response->assertJson([
            'preview' => [
                [
                    'customer_uuid' => $customer->uuid,
                    'name' => 'Preview Only Customer',
                    'current_bill' => '2500.00',
                    'new_bill' => '3000.00',
                ],
            ],
            'skipped' => [],
        ]);

        // The whole point of the preview endpoint: nothing was written.
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 2500]);
    }

    public function test_a_computed_bill_that_would_go_non_positive_is_skipped_not_crashed(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $wouldGoNegative = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 1000]);
        $staysValid = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000]);

        // The same -4000 FCFA fixed decrease applied to both: the first
        // customer's bill would go to -3000.00 (invalid, must be > 0) and
        // is skipped; the second's goes to 1000.00, which is still valid.
        $response = $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$wouldGoNegative->uuid, $staysValid->uuid],
            'mode' => 'increase_fixed',
            'value' => '-4000',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        // Skipped — bill unchanged, batch didn't crash.
        $this->assertDatabaseHas('customers', ['id' => $wouldGoNegative->id, 'bill' => 1000]);
        // The rest of the batch still succeeded.
        $this->assertDatabaseHas('customers', ['id' => $staysValid->id, 'bill' => 1000]);
    }

    public function test_preview_also_reports_a_skip_reason_for_an_invalid_resulting_bill(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 1000]);

        $response = $this->postJson('/customers/bulk-update-bill/preview', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'increase_fixed',
            'value' => '-4000',
        ]);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame([], $body['preview']);
        $this->assertArrayHasKey($customer->uuid, $body['skipped']);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 1000]);
    }

    public function test_bulk_update_requires_either_customer_uuids_or_a_filter(): void
    {
        $this->actingAsRole('manager');

        $response = $this->post('/customers/bulk-update-bill', [
            'mode' => 'set',
            'value' => '5000',
        ]);

        $response->assertSessionHasErrors('customer_uuids');
    }

    public function test_agent_gets_a_403_bulk_updating_bills(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'set',
            'value' => '5000',
        ])->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 2500]);
    }

    public function test_worker_gets_a_403_bulk_updating_bills(): void
    {
        $this->actingAsRole('worker');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'set',
            'value' => '5000',
        ])->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 2500]);
    }

    public function test_agent_gets_a_403_previewing_bulk_bill_updates(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        $this->postJson('/customers/bulk-update-bill/preview', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'set',
            'value' => '5000',
        ])->assertForbidden();
    }
}
