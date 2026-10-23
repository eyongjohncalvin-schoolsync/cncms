<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Support\BusinessTimezone;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Mobile "Request a bon" (docs/plans/expenditures-and-spending-controls.md,
 * item 2): an offline-queued sync item that becomes a pending bon by the
 * agent, idempotent on local_uuid, gated by `vouchers.request`, with an
 * optional receipt photo only its requester may attach while it is pending.
 */
class BonRequestSyncTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));

        $this->category = ExpenseCategoryFactory::new()->create();
        Company::query()->update(['voucher_approval_limit' => 200000, 'voucher_daily_limit' => 100000, 'monthly_spending_cap' => null]);
        Company::forgetCache();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function push(string $token, array $overrides = [], string $key = 'bon_requests'): array
    {
        $item = [
            'local_uuid' => (string) Str::uuid(),
            'category_uuid' => $this->category->uuid,
            'amount' => 4500,
            'payee_name' => 'Garage Mbeng',
            'purpose' => 'Puncture repair on the zone motorbike',
            ...$overrides,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', ['device_id' => 'phone-1', 'changes' => [$key => [$item]]])
            ->assertOk();

        return collect($response->json("results.{$key}"))->firstWhere('local_uuid', $item['local_uuid']) + ['local_uuid' => $item['local_uuid']];
    }

    private function tokenFor(string $email, string $role): string
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        return $user->createToken('api')->plainTextToken;
    }

    public function test_a_bon_request_becomes_a_pending_bon_once_however_often_it_is_retried(): void
    {
        $token = $this->tokenForRole('agent');
        $localUuid = (string) Str::uuid();

        $first = $this->push($token, ['local_uuid' => $localUuid, 'from_collections' => true, 'needed_on' => '2000-01-01']);
        $second = $this->push($token, ['local_uuid' => $localUuid, 'from_collections' => true]);

        $this->assertSame('synced', $first['status']);
        $this->assertSame($first['server_uuid'], $second['server_uuid']);

        $voucher = Voucher::query()->where('local_uuid', $localUuid)->sole();
        $this->assertSame('pending', $voucher->status);
        $this->assertSame('Garage Mbeng', $voucher->payee_name);
        $this->assertTrue($voucher->from_collections);
        $this->assertSame(User::query()->where('email', 'kelvin@shalomtech.dev')->value('id'), $voucher->requested_by);
        // A past "needed on" from a phone left offline becomes today.
        $this->assertSame(BusinessTimezone::today(), $voucher->needed_on->toDateString());
    }

    public function test_it_needs_the_bon_request_permission_not_just_sync_access(): void
    {
        // A worker passes nothing; an agent whose role lost vouchers.request fails per item.
        $token = $this->tokenForRole('agent');
        $agentRole = Role::query()->where('name', 'agent')->firstOrFail();
        $agentRole->permissions()->where('permission', 'vouchers.request')->delete();

        $entry = $this->push($token);

        $this->assertSame('failed', $entry['status']);
        $this->assertFalse(Voucher::query()->where('local_uuid', $entry['local_uuid'])->exists());
    }

    public function test_an_unknown_category_fails_only_that_item(): void
    {
        $entry = $this->push($this->tokenForRole('agent'), ['category_uuid' => (string) Str::uuid()]);

        $this->assertSame('failed', $entry['status']);
    }

    public function test_only_the_requester_attaches_the_receipt_and_only_while_pending(): void
    {
        $entry = $this->push($this->tokenForRole('agent'));
        $voucher = Voucher::query()->where('uuid', $entry['server_uuid'])->sole();

        $this->app['auth']->forgetGuards();
        $other = $this->tokenFor('patience@shalomtech.dev', 'agent');
        $this->withHeader('Authorization', "Bearer {$other}")
            ->post('/api/v1/sync/upload-receipt', ['entity_type' => 'voucher', 'entity_uuid' => $voucher->uuid, 'receipt' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $mine = $this->tokenForRole('agent');
        $this->withHeader('Authorization', "Bearer {$mine}")
            ->post('/api/v1/sync/upload-receipt', ['entity_type' => 'voucher', 'entity_uuid' => $voucher->uuid, 'receipt' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotNull($voucher->refresh()->receipt_path);

        $voucher->update(['status' => 'approved']);
        $this->withHeader('Authorization', "Bearer {$mine}")
            ->post('/api/v1/sync/upload-receipt', ['entity_type' => 'voucher', 'entity_uuid' => $voucher->uuid, 'receipt' => UploadedFile::fake()->image('r2.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
