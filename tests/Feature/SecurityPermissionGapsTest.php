<?php

namespace Tests\Feature;

use App\DataTransferObjects\CustomerServiceSelection;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\Role;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerSubscriptionService;
use App\Services\ReceiptStockService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 1 of the permission audit (owner, 2026-11-02): holes where a role
 * could do more than its permissions allow.
 */
class SecurityPermissionGapsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function member(string $email, string $role): TenantUser
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        return TenantUser::query()->where('user_id', $user->id)->firstOrFail();
    }

    /** A role that may sync (mobile.use_app) and nothing else that matters here. */
    private function syncOnlyToken(): string
    {
        Role::query()->create(['name' => 'observer', 'label' => 'Observer', 'is_system' => false])->syncPermissions(['mobile.use_app', 'customers.view']);

        return $this->tokenForRole('observer');
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'status' => 'active', 'bill' => 2500]);
    }

    /** An admin holds "Manage users" — the case that used to reach the owner role. */
    public function test_only_an_owner_gives_out_the_owner_role(): void
    {
        $this->member('kelvin@shalomtech.dev', 'admin');
        $divine = $this->member('divine@shalomtech.dev', 'agent');
        $this->actingAs(User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail());

        $this->patch("/users/{$divine->id}", ['role' => 'super'])->assertSessionHasErrors('role');
        $this->assertSame('agent', $divine->refresh()->role);
    }

    public function test_an_admin_cannot_change_or_deactivate_an_owner_nor_deactivate_themselves(): void
    {
        $manager = $this->member('kelvin@shalomtech.dev', 'admin');
        $owner = $this->member('patience@shalomtech.dev', 'super');
        $this->actingAs(User::query()->findOrFail($manager->user_id));

        $this->post("/users/{$owner->id}/deactivate")->assertForbidden();
        $this->patch("/users/{$owner->id}", ['role' => 'agent'])->assertForbidden();
        $this->post("/users/{$manager->id}/deactivate")->assertForbidden();
        $this->assertSame('super', $owner->refresh()->role);
    }

    public function test_an_owner_still_manages_another_owner(): void
    {
        $this->member('kelvin@shalomtech.dev', 'super');
        $owner = $this->member('patience@shalomtech.dev', 'super');
        $this->actingAs(User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail());

        $this->patch("/users/{$owner->id}", ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame('admin', $owner->refresh()->role);
    }

    public function test_a_phone_without_record_payments_cannot_push_a_payment(): void
    {
        $token = $this->syncOnlyToken();
        $customer = $this->customer();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-observer-1',
            'changes' => ['payments' => [['local_uuid' => (string) Str::uuid(), 'customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly']]],
        ])->assertOk()->assertJsonPath('results.payments.0.status', 'failed');

        $this->assertSame(0, Payment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_phone_without_log_complaints_cannot_push_a_complaint(): void
    {
        $token = $this->syncOnlyToken();
        $localUuid = (string) Str::uuid();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-observer-2',
            'changes' => ['complaints' => [['local_uuid' => $localUuid, 'category' => 'operational', 'title' => 'No signal', 'description' => 'Since morning']]],
        ])->assertOk()->assertJsonPath('results.complaints.0.status', 'failed');

        $this->assertFalse(Complaint::query()->where('local_uuid', $localUuid)->exists());
    }

    public function test_a_phone_cannot_void_someone_elses_unused_receipt_number(): void
    {
        $holder = User::query()->where('email', 'terence@shalomtech.dev')->firstOrFail();
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $holder, 25);
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-void-1',
            'changes' => ['payment_cancellations' => [['local_uuid' => (string) Str::uuid(), 'receipt_number' => $book->firstNumber(), 'reason' => 'Mistake']]],
        ])->assertOk();

        $this->assertSame(ReceiptLeaf::STATUS_UNUSED, ReceiptLeaf::query()->where('number', $book->firstNumber())->value('status'));
    }

    public function test_bulk_price_change_follows_the_custom_price_rule_and_the_minimum(): void
    {
        $tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $customer = $this->customer();
        app(CustomerSubscriptionService::class)->sync($customer, [new CustomerServiceSelection($tv->uuid, '2500')]);

        // Without "Set custom prices": a price that isn't the default or an option is skipped.
        Role::query()->create(['name' => 'pricer', 'label' => 'Pricer', 'is_system' => false])->syncPermissions(['customers.view', 'customers.update']);
        $this->actingAs(User::query()->findOrFail($this->member('kelvin@shalomtech.dev', 'pricer')->user_id));
        $this->post('/customers/bulk-update-bill', ['customer_uuids' => [$customer->uuid], 'mode' => 'set', 'value' => '4321.00'])->assertSessionHasNoErrors();
        $this->assertSame('2500.00', (string) $customer->refresh()->bill);

        // With it, never below the minimum.
        $tv->update(['min_price' => 2000]);
        $this->member('kelvin@shalomtech.dev', 'manager');
        $this->post('/customers/bulk-update-bill', ['customer_uuids' => [$customer->uuid], 'mode' => 'set', 'value' => '1500.00'])->assertSessionHasNoErrors();
        $this->assertSame('2500.00', (string) $customer->refresh()->bill);

        $this->post('/customers/bulk-update-bill', ['customer_uuids' => [$customer->uuid], 'mode' => 'set', 'value' => '4321.00'])->assertSessionHasNoErrors();
        $this->assertSame('4321.00', (string) $customer->refresh()->bill);
    }
}
