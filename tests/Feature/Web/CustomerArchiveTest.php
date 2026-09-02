<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerService;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Customer archiving (customer-deletion deliberation, 2026-08-29). A
 * customer with billing history is soft-deleted (archived), never
 * hard-deleted — the history stays physically in place and auditable, and
 * Archive ⇄ Restore is reversible. A genuinely-empty junk row still hard
 * deletes (see CustomerTest::test_a_customer_with_zero_history_can_still_be_deleted).
 */
class CustomerArchiveTest extends TestCase
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

    private function customerWithHistory(): Customer
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'ARCH-'.uniqid()]);
        ManuscriptFactory::new()->create(['customer_id' => $customer->id]);

        return $customer;
    }

    public function test_manager_can_archive_a_customer_with_billing_history(): void
    {
        $actor = $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $response = $this->patch("/customers/{$customer->uuid}/archive", [
            'name' => $customer->name,
            'reason' => 'Moved out of Kumba 3, line cut April 2026.',
        ]);

        $response->assertRedirect('/customers');
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'archived_by' => $actor->id,
            'archived_reason' => 'Moved out of Kumba 3, line cut April 2026.',
        ]);
        // History untouched.
        $this->assertDatabaseHas('manuscripts', ['customer_id' => $customer->id]);
    }

    public function test_archiving_writes_one_archived_customer_audit_row_and_no_delete_row(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $this->patch("/customers/{$customer->uuid}/archive", [
            'name' => $customer->name,
            'reason' => 'Business closed.',
        ])->assertRedirect();

        $rows = AuditLog::query()
            ->where('table_name', 'customers')
            ->where('record_uuid', $customer->uuid)
            ->get();

        $this->assertTrue($rows->every(fn (AuditLog $r): bool => $r->action !== 'delete'));
        $this->assertTrue(
            $rows->contains(fn (AuditLog $r): bool => ! empty($r->new_values['archived_by'] ?? null)),
            'expected an update row that sets archived_by',
        );
    }

    public function test_archive_rejects_a_mistyped_name(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $response = $this->from("/customers/{$customer->uuid}")->patch("/customers/{$customer->uuid}/archive", [
            'name' => 'Not The Right Name',
            'reason' => 'whatever',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_archive_requires_a_reason(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $response = $this->from("/customers/{$customer->uuid}")->patch("/customers/{$customer->uuid}/archive", [
            'name' => $customer->name,
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_an_archived_customer_is_hidden_from_the_active_list_and_shown_in_the_archived_view(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();
        $this->customers()->archive($customer->fresh(), 1, 'gone');

        $this->get('/customers?search='.urlencode($customer->name))
            ->assertInertia(fn ($page) => $page->where('customers.data', []));

        $this->get('/customers?archived=1&search='.urlencode($customer->name))
            ->assertInertia(fn ($page) => $page->where('customers.data.0.uuid', $customer->uuid));
    }

    public function test_an_archived_customers_detail_page_still_renders_read_only(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();
        $this->customers()->archive($customer->fresh(), 1, 'gone');

        $this->get("/customers/{$customer->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customer.archived_at', fn ($v) => $v !== null));
    }

    public function test_manager_can_restore_an_archived_customer(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();
        $this->customers()->archive($customer->fresh(), 1, 'gone');

        $response = $this->patch("/customers/{$customer->uuid}/restore");

        $response->assertRedirect("/customers/{$customer->uuid}");
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'deleted_at' => null,
            'archived_by' => null,
            'archived_reason' => null,
        ]);
    }

    public function test_an_active_customer_route_404s_on_a_trashed_uuid(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();
        $this->customers()->archive($customer->fresh(), 1, 'gone');

        $this->get("/customers/{$customer->uuid}/edit")->assertNotFound();
    }

    public function test_agent_and_worker_cannot_archive_or_restore(): void
    {
        foreach (['agent', 'worker'] as $role) {
            $this->actingAsRole($role);
            $customer = $this->customerWithHistory();

            $this->patch("/customers/{$customer->uuid}/archive", [
                'name' => $customer->name,
                'reason' => 'nope',
            ])->assertForbidden();

            $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
        }
    }

    private function customers(): CustomerService
    {
        return app(CustomerService::class);
    }
}
