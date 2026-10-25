<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\FieldInstallation;
use App\Models\FreeMonthGrant;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptLeaf;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\RemittanceService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\RegistersFieldInstallations;
use Tests\TestCase;

/**
 * Offline field installations, the phone's side
 * (docs/plans/installation-and-promotions.md, phase 3): the `installations`
 * sync item — one transaction, idempotent, own zone only, receipts from the
 * phone's block, duplicates flagged — and what the pull tells the phone.
 */
class FieldInstallationSyncTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use RegistersFieldInstallations;

    private Zone $zone;

    private Service $tv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->zone = ZoneFactory::new()->create();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->tv->update(['price' => 2500]);

        // Cash controls "went live" before the travelled-to day.
        Company::query()->update(['cash_controls_start_date' => '2026-09-01']);
        Cache::forget(Company::CACHE_KEY);
        Company::flushMemo();
    }

    public function test_an_agent_registers_a_customer_offline_as_pending_activation(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);

        $result = $this->pushInstallations($phone, [$item])['results']['installations'][0];

        $this->assertSame('synced', $result['status']);
        $this->assertSame($item['customer_uuid'], $result['server_uuid']);
        $this->assertSame('pending', $result['activation']);
        $this->assertCount(2, $result['payments']);

        $customer = $this->registeredCustomer($item);
        $this->assertTrue($customer->isPendingActivation());
        $this->assertNull($customer->billing_starts_period);
        $this->assertSame('2026-10-10', $customer->connected_on->toDateString());
        $this->assertSame('2500.00', (string) $customer->bill);

        // No free months running until the office activates.
        $this->assertSame(0, FreeMonthGrant::query()->where('customer_id', $customer->id)->count());

        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('10000.00', (string) $charge->amount);
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);

        $field = FieldInstallation::query()->where('local_uuid', $item['local_uuid'])->sole();
        $this->assertSame(FieldInstallation::STATUS_PENDING, $field->status);
        $this->assertSame(1, $field->proposal['service_plans'][0]['free_months']);
        $this->assertSame($charge->id, $field->customer_charge_id);

        $payments = Payment::query()->with('receipt')->where('field_installation_id', $field->id)->orderBy('id')->get();
        $this->assertSame(['subscription', 'installation'], $payments->pluck('purpose')->all());
        $this->assertSame(['5000.00', '10000.00'], $payments->map(fn (Payment $p): string => (string) $p->amount)->all());
        $this->assertEquals([['service_id' => $this->tv->id, 'months' => 2, 'rate' => '2500.00']], $payments[0]->service_months);
        $this->assertTrue($payments->every(fn (Payment $p): bool => $p->verification_status === 'verified' && $p->recorded_offline));
        $this->assertSame($item['installation_receipt']['local_uuid'], $payments[1]->local_uuid);

        // The receipts the phone printed are the payments' receipts.
        $this->assertSame([$item['upfront_receipt']['receipt_number'], $item['installation_receipt']['receipt_number']], $payments->map(fn (Payment $p): string => $p->receipt->receipt_number)->all());
        $this->assertSame(PaymentReceipt::SOURCE_DIGITAL, $payments[1]->receipt->source);
        $this->assertSame(2, ReceiptLeaf::query()->whereIn('number', array_slice($phone['numbers'], 0, 2))->where('status', ReceiptLeaf::STATUS_USED)->count());

        // The money is in the agent's remittance at once.
        $agent = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $outstanding = app(RemittanceService::class)->outstandingQuery($agent)->pluck('id');
        $this->assertTrue($payments->pluck('id')->every(fn (int $id): bool => $outstanding->contains($id)));
    }

    public function test_a_replayed_registration_creates_nothing_twice(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);

        $first = $this->pushInstallations($phone, [$item])['results']['installations'][0];
        $again = $this->pushInstallations($phone, [$item, $item])['results']['installations'];

        $this->assertSame('synced', $again[0]['status']);
        $this->assertSame($first['server_uuid'], $again[0]['server_uuid']);
        $this->assertSame($first['server_uuid'], $again[1]['server_uuid']);
        $this->assertSame(1, Customer::query()->where('uuid', $item['customer_uuid'])->count());
        $this->assertSame(1, FieldInstallation::query()->where('local_uuid', $item['local_uuid'])->count());
        $this->assertSame(2, Payment::query()->whereHas('customer', fn ($q) => $q->where('uuid', $item['customer_uuid']))->count());
    }

    public function test_a_registration_outside_the_agents_zone_is_refused_and_stores_nothing(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, ZoneFactory::new()->create(), $this->tv->uuid);

        $result = $this->pushInstallations($phone, [$item])['results']['installations'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('own zone', $result['error']);
        $this->assertFalse(Customer::query()->withTrashed()->where('uuid', $item['customer_uuid'])->exists());
    }

    public function test_the_item_needs_the_field_installation_permission(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);

        $roleId = DB::connection('tenant')->table('roles')->where('name', 'agent')->value('id');
        DB::connection('tenant')->table('role_permissions')->where('role_id', $roleId)->where('permission', 'installations.field')->delete();

        $response = $this->pushInstallations($phone, [$item]);

        $this->assertSame('failed', $response['results']['installations'][0]['status']);
        $this->assertSame('installation', $response['errors'][0]['entity_type']);
        $this->assertFalse(Customer::query()->withTrashed()->where('uuid', $item['customer_uuid'])->exists());
    }

    public function test_a_tampered_receipt_refuses_the_whole_registration(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);
        $item['installation_paid'] = '9000';

        $result = $this->pushInstallations($phone, [$item])['results']['installations'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertFalse(Customer::query()->withTrashed()->where('uuid', $item['customer_uuid'])->exists());
        $this->assertSame(0, ReceiptLeaf::query()->whereIn('number', array_slice($phone['numbers'], 0, 2))->where('status', ReceiptLeaf::STATUS_USED)->count());
    }

    public function test_the_upfront_amount_must_match_the_prices(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);
        $item['services'][0]['price'] = '3000';

        $result = $this->pushInstallations($phone, [$item])['results']['installations'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertFalse(Customer::query()->withTrashed()->where('uuid', $item['customer_uuid'])->exists());
    }

    public function test_a_possible_duplicate_is_flagged_never_blocked(): void
    {
        $existing = CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'name' => 'Ekema Divine Field', 'phone' => '677123456', 'status' => 'active']);
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid, ['name' => 'Someone Else', 'phone' => '+237 677 12 34 56']);

        $result = $this->pushInstallations($phone, [$item])['results']['installations'][0];

        $this->assertSame('synced', $result['status']);
        $this->assertTrue($result['possible_duplicate']);

        $field = FieldInstallation::query()->where('local_uuid', $item['local_uuid'])->sole();
        $this->assertSame($existing->uuid, $field->possible_duplicates[0]['uuid']);
        $this->assertTrue($this->registeredCustomer($item)->isPendingActivation());
    }

    public function test_a_payment_for_the_new_customer_in_the_same_push_lands_after_it(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid, paidMonths: 0);

        $response = $this->withHeader('Authorization', "Bearer {$phone['token']}")->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-'.$phone['device'],
            'changes' => [
                'payments' => [['local_uuid' => (string) Str::uuid(), 'customer_uuid' => $item['customer_uuid'], 'amount' => 2500, 'frequency' => 'monthly']],
                'installations' => [$item],
            ],
        ])->assertOk();

        $this->assertSame('synced', $response->json('results.installations.0.status'));
        $this->assertSame('synced', $response->json('results.payments.0.status'));
    }

    public function test_the_pull_tells_the_phone_where_the_office_is(): void
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid);
        $this->pushInstallations($phone, [$item]);

        $pull = $this->withHeader('Authorization', "Bearer {$phone['token']}")->getJson('/api/v1/sync/pull')->assertOk();

        $this->assertSame([[
            'local_uuid' => $item['local_uuid'],
            'customer_uuid' => $item['customer_uuid'],
            'customer_name' => mb_strtoupper($item['name']),
            'status' => 'pending',
            'reason' => null,
            'possible_duplicate' => false,
            'decided_at' => null,
        ]], $pull->json('changes.installations'));

        $row = collect($pull->json('changes.customers.upserted'))->firstWhere('uuid', $item['customer_uuid']);
        $this->assertSame('pending', $row['activation_status']);
    }

    public function test_the_agent_gets_the_installation_options_to_cache(): void
    {
        $this->fieldAgentPhone($this->zone);
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'agent']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('agent'))
            ->getJson('/api/v1/customers/installation-options')
            ->assertOk()
            ->assertJsonPath('data.can_register_in_field', true)
            ->assertJsonPath('data.can_manage', false);
    }
}
