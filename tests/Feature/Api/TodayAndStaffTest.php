<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\CashMovement;
use App\Models\LoginFailure;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashBookService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Today and staff on the phone (docs/plans/mobile-manager-app.md, wave 5):
 * the read-only Today screen for whoever sees the cash book, and the
 * everyday staff decisions with the Users Control Center's rules. Seeded
 * swecom members: kelvin (super), patience (admin), terence (manager),
 * divine (agent).
 */
class TodayAndStaffTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        TenantUser::query()->where('tenant_id', 'swecom')->update(['branch_id' => null, 'is_investor' => false]);

        $this->role('kelvin@shalomtech.dev', 'super');
        $this->role('patience@shalomtech.dev', 'admin');
        $this->role('terence@shalomtech.dev', 'manager');
        $this->role('divine@shalomtech.dev', 'agent');
    }

    private function role(string $email, string $role): User
    {
        $user = $this->user($email);
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function phone(string $email): User
    {
        $user = $this->user($email);
        app()->forgetInstance(TenantContext::class);
        Sanctum::actingAs($user);

        return $user;
    }

    private function member(string $email): TenantUser
    {
        return TenantUser::query()->where('user_id', $this->user($email)->id)->firstOrFail();
    }

    public function test_today_shows_the_days_collections_and_the_months_checks(): void
    {
        $this->actingAs($this->user('terence@shalomtech.dev'));
        app()->forgetInstance(TenantContext::class);
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        $this->phone('patience@shalomtech.dev');
        $response = $this->getJson('/api/v1/today')->assertOk();

        $this->assertGreaterThanOrEqual(2500, (float) $response->json('data.collections.total'));
        $this->assertContains($this->user('terence@shalomtech.dev')->name, array_column($response->json('data.collections.by_collector'), 'name'));
        $this->assertNotEmpty($response->json('data.balance.checks'));
        $this->assertArrayHasKey('best', $response->json('data.zones'));

        // A field agent doesn't see the company's money.
        $this->phone('divine@shalomtech.dev');
        $this->getJson('/api/v1/today')->assertForbidden();
    }

    public function test_the_staff_list_says_who_may_be_managed(): void
    {
        $this->phone('patience@shalomtech.dev');

        $staff = collect($this->getJson('/api/v1/staff')->assertOk()->assertJsonPath('data.can_manage', true)->json('data.staff'))->keyBy('username');
        $me = $staff->firstWhere('is_me', true);
        $this->assertFalse($me['can_manage'], 'Nobody manages their own account from the phone.');
        $this->assertTrue($staff->firstWhere('name', $this->user('divine@shalomtech.dev')->name)['can_manage']);

        $this->phone('divine@shalomtech.dev');
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_a_role_change_has_the_web_rules_and_side_effects(): void
    {
        $divine = $this->member('divine@shalomtech.dev');

        $this->phone('patience@shalomtech.dev');
        $this->patchJson("/api/v1/staff/{$divine->id}/role", ['role' => 'manager'])->assertOk()->assertJsonPath('message', fn (string $message) => str_ends_with($message, 'is now Manager.'));

        $this->assertSame('manager', $divine->fresh()->role);
        $this->assertNotSame('active', Agent::query()->withoutGlobalScopes()->where('user_id', $divine->user_id)->value('status'), 'No longer a field agent: the agent record is retired.');

        // Never your own role; never the last owner.
        $this->patchJson('/api/v1/staff/'.$this->member('patience@shalomtech.dev')->id.'/role', ['role' => 'manager'])->assertJsonValidationErrors('role');
        // Only an owner changes an owner (2026-11-02): an admin is refused outright.
        $this->patchJson('/api/v1/staff/'.$this->member('kelvin@shalomtech.dev')->id.'/role', ['role' => 'manager'])->assertForbidden();
        $this->patchJson("/api/v1/staff/{$divine->id}/role", ['role' => 'no-such-role'])->assertJsonValidationErrors('role');

        // A manager without users.manage can't.
        $this->phone('terence@shalomtech.dev');
        $this->patchJson("/api/v1/staff/{$divine->id}/role", ['role' => 'agent'])->assertForbidden();
    }

    public function test_deactivating_signs_out_everywhere_but_never_while_holding_money(): void
    {
        $divine = $this->member('divine@shalomtech.dev');
        $terence = $this->member('terence@shalomtech.dev');
        $cashBook = app(CashBookService::class);
        $cashBook->record($cashBook->tillFor($terence->user), 'in', '3000', CashMovement::KIND_TRANSFER, $terence->user, ['note' => 'test float']);
        $divine->user->createToken('phone');

        $this->phone('patience@shalomtech.dev');
        $this->postJson("/api/v1/staff/{$terence->id}/deactivate")->assertJsonValidationErrors('user');
        // Never yourself — now refused by the policy itself (2026-11-02), web and phone alike.
        $this->postJson('/api/v1/staff/'.$this->member('patience@shalomtech.dev')->id.'/deactivate')->assertForbidden();

        $this->postJson("/api/v1/staff/{$divine->id}/deactivate")->assertOk();
        $this->assertSame('passive', $divine->user->fresh()->status);
        $this->assertSame(0, $divine->user->tokens()->count());

        $this->postJson("/api/v1/staff/{$divine->id}/activate")->assertOk();
        $this->assertSame('active', $divine->user->fresh()->status);
    }

    public function test_a_blocked_sign_in_is_lifted_from_the_phone(): void
    {
        $divine = $this->member('divine@shalomtech.dev');

        foreach (range(1, (int) config('rate-limits.login_block.block_after')) as $attempt) {
            LoginFailure::query()->forceCreate([
                'user_id' => $divine->user_id,
                'identifier' => $divine->user->username,
                'reason' => LoginFailure::WRONG_PASSWORD,
                'channel' => LoginFailure::MOBILE,
                'ip_address' => '10.0.0.9',
                'created_at' => now()->subSeconds(60 - $attempt),
            ]);
        }

        $this->phone('patience@shalomtech.dev');
        $row = fn (): array => collect($this->getJson('/api/v1/staff')->json('data.staff'))->firstWhere('id', $divine->id);
        $this->assertNotNull($row()['sign_in_blocked_until']);

        $this->postJson("/api/v1/staff/{$divine->id}/unblock")->assertOk();
        $this->assertNull($row()['sign_in_blocked_until']);
    }
}
