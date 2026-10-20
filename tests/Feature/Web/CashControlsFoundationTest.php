<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Auth\Permission;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\DocumentNumberService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash controls wave 1 (docs/plans/cash-controls.md): the cashier role and
 * new permissions, how a payment was made (cash / MTN MoMo / Orange Money /
 * bank + transaction ID) on every entry path, the per-company voucher
 * limits, and the cash-document number series.
 */
class CashControlsFoundationTest extends TestCase
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
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        $this->actingAs($user);

        return $user;
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'status' => 'active',
        ]);
    }

    public function test_the_cashier_role_exists_with_the_cash_permissions_and_without_approval(): void
    {
        $cashier = Role::query()->where('name', 'cashier')->firstOrFail();
        $permissions = $cashier->permissions()->pluck('permission')->all();

        $this->assertTrue($cashier->is_system);
        $this->assertContains(Permission::CashReceive->value, $permissions);
        $this->assertContains(Permission::VouchersPay->value, $permissions);
        $this->assertNotContains(Permission::VouchersApprove->value, $permissions);
    }

    public function test_existing_system_roles_are_topped_up_with_the_new_permissions(): void
    {
        $permissionsOf = fn (string $role): array => Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('permission')->all();

        $this->assertContains(Permission::VouchersApprove->value, $permissionsOf('manager'));
        $this->assertContains(Permission::ReceiptsManageStock->value, $permissionsOf('manager'));
        $this->assertContains(Permission::CashRemit->value, $permissionsOf('agent'));
        $this->assertNotContains(Permission::VouchersApprove->value, $permissionsOf('agent'));
        $this->assertContains(Permission::VouchersPay->value, $permissionsOf('admin'));
    }

    public function test_the_new_permissions_are_grouped_for_the_roles_matrix(): void
    {
        $this->assertSame('Cash & receipts', Permission::CashReceive->area());
        $this->assertSame('Cash & receipts', Permission::ReceiptsManageStock->area());
        $this->assertSame('Vouchers', Permission::VouchersApprove->area());
    }

    public function test_cashier_is_a_reserved_role_name(): void
    {
        $this->actingAsRole('super');

        $this->post('/users/roles', ['name' => 'cashier', 'label' => 'Another cashier'])->assertSessionHasErrors('name');
    }

    public function test_a_payment_defaults_to_cash(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();

        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->method_reference);
    }

    public function test_a_mobile_money_payment_needs_its_transaction_id(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();

        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'mtn_momo'])
            ->assertSessionHasErrors('method_reference');

        $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
            'method' => 'orange_money',
            'method_reference' => ' MP241020.1532.A12345 ',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'method' => 'orange_money',
            'method_reference' => 'MP241020.1532.A12345',
        ]);
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'cheque'])
            ->assertSessionHasErrors('method');
    }

    public function test_a_bank_payment_may_omit_its_reference(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();

        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'bank'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'method' => 'bank']);
    }

    public function test_editing_a_payment_can_correct_its_method(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly']);
        $payment = Payment::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();

        $this->put("/payments/{$payment->uuid}", ['method' => 'mtn_momo'])->assertSessionHasErrors('method_reference');

        $this->put("/payments/{$payment->uuid}", ['method' => 'mtn_momo', 'method_reference' => 'CI241020.0001'])
            ->assertSessionHasNoErrors();

        $this->assertSame('mtn_momo', $payment->refresh()->method);
        $this->assertSame('CI241020.0001', $payment->method_reference);
    }

    public function test_sync_push_stores_the_method_and_refuses_mobile_money_without_an_id(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('super');
        $withId = (string) Str::uuid();
        $withoutId = (string) Str::uuid();
        $legacy = (string) Str::uuid();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-cash-1',
            'last_sync_at' => null,
            'changes' => [
                'payments' => [
                    ['local_uuid' => $withId, 'customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => 'CI1'],
                    ['local_uuid' => $withoutId, 'customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'orange_money'],
                    // An older app build: no method at all.
                    ['local_uuid' => $legacy, 'customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'],
                ],
            ],
        ])->assertOk();

        $results = collect($response->json('results.payments'))->keyBy('local_uuid');

        $this->assertSame('synced', $results[$withId]['status']);
        $this->assertSame('failed', $results[$withoutId]['status']);
        $this->assertSame('synced', $results[$legacy]['status']);

        $this->assertDatabaseHas('payments', ['uuid' => $results[$withId]['server_uuid'], 'method' => 'mtn_momo', 'method_reference' => 'CI1']);
        $this->assertDatabaseHas('payments', ['uuid' => $results[$legacy]['server_uuid'], 'method' => 'cash']);
    }

    public function test_the_api_exposes_the_method(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('super');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
            'method' => 'mtn_momo',
            'method_reference' => 'CI99',
        ])->assertCreated()
            ->assertJsonPath('data.method', 'mtn_momo')
            ->assertJsonPath('data.method_reference', 'CI99');
    }

    public function test_an_admin_sets_the_voucher_limits_and_blank_means_no_limit(): void
    {
        $this->actingAsRole('admin');
        $company = Company::cached();

        $payload = [
            'name' => $company->name,
            'location' => $company->location,
            'phone' => $company->phone,
            'reconnection_fine' => '2000',
            'arrears_second_approval_threshold' => '20000',
        ];

        $this->patch('/settings/company', [...$payload, 'voucher_approval_limit' => '50000', 'voucher_daily_limit' => '200000', 'remittance_deadline_hours' => 12, 'advance_settlement_days' => 5])
            ->assertSessionHasNoErrors();

        $company = Company::query()->firstOrFail();
        $this->assertSame('50000.00', (string) $company->voucher_approval_limit);
        $this->assertSame('200000.00', (string) $company->voucher_daily_limit);
        $this->assertSame(12, $company->remittance_deadline_hours);
        $this->assertSame(5, $company->advance_settlement_days);

        $this->patch('/settings/company', [...$payload, 'voucher_approval_limit' => '', 'voucher_daily_limit' => ''])
            ->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertNull($company->voucher_approval_limit);
        $this->assertNull($company->voucher_daily_limit);
        $this->assertSame(12, $company->remittance_deadline_hours);
    }

    public function test_a_manager_cannot_change_the_limits(): void
    {
        $this->actingAsRole('manager');

        $this->patch('/settings/company', ['voucher_approval_limit' => '1'])->assertForbidden();
    }

    public function test_document_numbers_are_sequential_per_series(): void
    {
        $numbers = app(DocumentNumberService::class);
        $year = now()->format('Y');

        [$first, $second, $voucher] = DB::transaction(fn (): array => [
            $numbers->next(DocumentNumberService::REMITTANCE),
            $numbers->next(DocumentNumberService::REMITTANCE),
            $numbers->next(DocumentNumberService::VOUCHER),
        ]);

        $this->assertMatchesRegularExpression("/^RM-{$year}-\\d{6}$/", $first);
        $this->assertSame((int) substr($first, -6) + 1, (int) substr($second, -6));
        $this->assertStringStartsWith("BC-{$year}-", $voucher);
    }

    public function test_document_numbers_refuse_an_unknown_series_or_no_transaction(): void
    {
        $numbers = app(DocumentNumberService::class);

        $this->expectException(InvalidArgumentException::class);
        DB::transaction(fn () => $numbers->next('XX'));
    }
}
