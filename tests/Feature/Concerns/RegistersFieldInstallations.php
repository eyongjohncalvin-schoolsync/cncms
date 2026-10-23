<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;
use App\Support\ReceiptSecurityCode;
use Database\Factories\AgentFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the phone does for an offline field installation (installation
 * phase 3): register for receipts, draw numbers from its block, sign each
 * receipt with the customer uuid it generated, and push the registration.
 * Used with Tests\Feature\Api\Concerns\InteractsWithTenantRoles; the seeded
 * owner plays the field agent (with an Agent row in $zone).
 */
trait RegistersFieldInstallations
{
    /**
     * Makes the seeded owner a field agent of $zone and registers their
     * phone for receipts.
     *
     * @return array{token: string, device: string, secret: string, numbers: list<string>}
     */
    protected function fieldAgentPhone(Zone $zone): array
    {
        $token = $this->tokenForRole('agent');
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => $user->id]);

        $registered = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/field-devices', ['name' => 'Tecno Spark (field test)'])
            ->assertCreated();

        $device = $registered->json('data.device_uuid');

        $numbers = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Field-Device' => $device])
            ->getJson('/api/v1/receipt-stock')
            ->assertOk()
            ->json('data.numbers');

        return ['token' => $token, 'device' => $device, 'secret' => $registered->json('data.secret'), 'numbers' => $numbers];
    }

    /**
     * A signed receipt as the phone prints it.
     *
     * @param  array{secret: string, numbers: list<string>}  $phone
     * @return array{local_uuid: string, receipt_number: string, security_code: string, receipt_issued_at: string, amount: string}
     */
    protected function signedReceipt(array $phone, int $leaf, string $customerUuid, string $amount, ?Carbon $at = null): array
    {
        $at ??= Carbon::now()->subMinutes(20)->startOfSecond();
        $number = $phone['numbers'][$leaf];

        return [
            'local_uuid' => (string) Str::uuid(),
            'receipt_number' => $number,
            'security_code' => ReceiptSecurityCode::format(ReceiptSecurityCode::compute(
                ReceiptSecurityCode::deviceKey($phone['secret']),
                ReceiptSecurityCode::canonical($number, $customerUuid, $amount, $at->getTimestamp()),
            )),
            'receipt_issued_at' => $at->toIso8601String(),
            'amount' => $amount,
        ];
    }

    /**
     * One registration item: TV at 2,500, installation fee 10,000 paid in
     * full, $paidMonths of TV paid upfront, the installation month free.
     *
     * @param  array{secret: string, numbers: list<string>}  $phone
     * @return array<string, mixed>
     */
    protected function registration(array $phone, Zone $zone, string $tvUuid, array $extra = [], int $paidMonths = 2, string $paid = '10000', int $firstLeaf = 0): array
    {
        $customerUuid = (string) Str::uuid();
        $upfront = number_format($paidMonths * 2500, 2, '.', '');

        return [
            'local_uuid' => (string) Str::uuid(),
            'customer_uuid' => $customerUuid,
            'created_at' => Carbon::now()->subMinutes(20)->toIso8601String(),
            'zone_uuid' => $zone->uuid,
            'name' => 'Field Customer '.Str::random(6),
            'phone' => '6'.random_int(70000000, 79999999),
            'services' => [['service_uuid' => $tvUuid, 'price' => '2500']],
            'installation_fee' => '10000',
            'installation_paid' => $paid,
            'connected_on' => Carbon::now()->toDateString(),
            'service_plans' => [['service_uuid' => $tvUuid, 'free_from' => Carbon::now()->format('Y-m'), 'free_months' => 1, 'paid_months' => $paidMonths]],
            'method' => 'cash',
            'installation_receipt' => bccomp($paid, '0', 2) > 0 ? $this->signedReceipt($phone, $firstLeaf, $customerUuid, $paid) : null,
            'upfront_receipt' => $paidMonths > 0 ? $this->signedReceipt($phone, $firstLeaf + 1, $customerUuid, $upfront) : null,
            ...$extra,
        ];
    }

    /**
     * @param  array{token: string, device: string}  $phone
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function pushInstallations(array $phone, array $items): array
    {
        return $this->withHeader('Authorization', "Bearer {$phone['token']}")->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-'.$phone['device'],
            'last_sync_at' => null,
            'changes' => ['installations' => $items],
        ])->assertOk()->json();
    }

    protected function registeredCustomer(array $item): Customer
    {
        return Customer::query()->withTrashed()->where('uuid', $item['customer_uuid'])->sole();
    }
}
