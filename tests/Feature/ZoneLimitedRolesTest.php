<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ZoneCoverageService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 3 of the permission audit (owner, 2026-11-02): "limited to their own
 * zones" is a role setting, not the role being named agent, and verifying /
 * disconnecting in one's own zones are permissions.
 */
class ZoneLimitedRolesTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    /** Signed in as $user, with their context bound as a request would (zones resolved now). */
    private function actAs(User $user): void
    {
        app()->instance(TenantContext::class, TenantContext::resolve(TenantUser::query()->where('user_id', $user->id)->firstOrFail()));
        Sanctum::actingAs($user);
    }

    private function collector(): User
    {
        Role::query()->create(['name' => 'collectors', 'label' => 'Collectors', 'is_system' => false, 'zone_limited' => true])
            ->syncPermissions(['customers.view', 'payments.view', 'payments.create', 'payments.verify_zone', 'mobile.use_app']);

        $user = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'collectors', 'branch_id' => null]);

        return $user;
    }

    public function test_a_custom_field_role_works_only_its_own_zones(): void
    {
        $user = $this->collector();
        $mine = ZoneFactory::new()->create();
        $other = ZoneFactory::new()->create();
        $agent = Agent::query()->withoutGlobalScopes()->where('user_id', $user->id)->first() ?? AgentFactory::new()->create(['zone_id' => $mine->id, 'user_id' => $user->id]);
        app(ZoneCoverageService::class)->addZone($agent, $mine, null);

        $inZone = PaymentFactory::new()->create(['customer_id' => CustomerFactory::new()->create(['zone_id' => $mine->id, 'status' => 'active'])->id, 'verification_status' => 'pending']);
        $outside = PaymentFactory::new()->create(['customer_id' => CustomerFactory::new()->create(['zone_id' => $other->id, 'status' => 'active'])->id, 'verification_status' => 'pending']);

        $context = TenantContext::resolve(TenantUser::query()->where('user_id', $user->id)->firstOrFail());
        $this->assertTrue($context->isZoneLimited());
        $this->assertContains($mine->id, $context->zoneIds);
        $this->assertNotContains($other->id, $context->zoneIds);

        $this->actAs($user);
        $this->assertTrue($user->can('verify', $inZone->load('customer')));
        $this->assertFalse($user->can('verify', $outside->load('customer')));
    }

    public function test_without_verify_in_zone_a_field_role_cannot_verify(): void
    {
        Role::query()->create(['name' => 'readers', 'label' => 'Readers', 'is_system' => false, 'zone_limited' => true])->syncPermissions(['payments.view']);
        $user = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'readers', 'branch_id' => null]);
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => $user->id]);
        app(ZoneCoverageService::class)->addZone($agent, $zone, null);

        $payment = PaymentFactory::new()->create(['customer_id' => CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active'])->id, 'verification_status' => 'pending']);

        $this->actAs($user);
        $this->assertFalse($user->can('verify', $payment->load('customer')));
    }

    public function test_the_agent_role_is_still_zone_limited_with_its_in_zone_permissions(): void
    {
        $agent = Role::query()->where('name', 'agent')->firstOrFail();

        $this->assertTrue($agent->zone_limited);
        $this->assertContains('payments.verify_zone', $agent->permissionValues());
        $this->assertContains('customers.disconnect_zone', $agent->permissionValues());
    }

    public function test_switching_a_role_to_zone_limited_gives_its_members_a_field_record(): void
    {
        $owner = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $owner->id)->update(['role' => 'super']);
        $role = Role::query()->create(['name' => 'technicians', 'label' => 'Technicians', 'is_system' => false]);
        $member = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $member->id)->update(['role' => 'technicians']);
        Agent::query()->withoutGlobalScopes()->where('user_id', $member->id)->update(['status' => 'inactive']);

        $this->actingAs($owner)->patch("/users/roles/{$role->uuid}", ['label' => 'Technicians', 'zone_limited' => true])->assertSessionHasNoErrors();

        $this->assertTrue($role->refresh()->zone_limited);
        $this->assertSame('active', Agent::query()->withoutGlobalScopes()->where('user_id', $member->id)->value('status'));
    }

    public function test_the_per_person_payment_grant_works_for_any_role(): void
    {
        Role::query()->create(['name' => 'front_desk', 'label' => 'Front desk', 'is_system' => false])->syncPermissions(['customers.view']);
        $user = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'front_desk', 'can_record_payments' => true]);

        $this->actAs($user);
        $this->assertTrue($user->can('create', Payment::class));
    }
}
