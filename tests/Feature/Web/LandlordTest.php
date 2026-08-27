<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Coverage for the central/platform-level "landlord" area
 * (App\Http\Middleware\EnsureLandlord, App\Http\Controllers\Landlord\
 * TenantController, routes/web/landlord.php).
 *
 * Deliberately does NOT use DatabaseTransactions (unlike most other Web
 * feature tests). Landlord pages never initialize tenancy at all now
 * (EnsureLandlord is a pure central `users.is_landlord` check — see that
 * middleware's doc comment), but provisioning a tenant still runs
 * `CREATE SCHEMA` on one connection and then the tenant migrations on a
 * *separate* connection/session (search_path=tenant_{slug}). If that were
 * wrapped in an outer test transaction, the migration session couldn't see
 * the new schema yet (Postgres DDL is only visible to other sessions after
 * COMMIT). So provisioning tests let the tenant really get created, then
 * clean it up themselves via $tenant->delete() (Stancl's TenantDeleted ->
 * DeleteDatabase pipeline, which drops the schema).
 *
 * `is_landlord` toggles below are real, immediately-committed writes on the
 * central `users` row for the same reason — restored afterwards.
 */
class LandlordTest extends TestCase
{
    private function owner(): User
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
    }

    private function setOwnerIsLandlord(bool $isLandlord): void
    {
        $this->owner()->forceFill(['is_landlord' => $isLandlord])->save();
    }

    public function test_landlord_can_view_the_tenants_index_and_sees_the_seeded_swecom_tenant(): void
    {
        $this->setOwnerIsLandlord(true);

        $this->actingAs($this->owner());

        $response = $this->get('/landlord/tenants');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Landlord/Tenants/Index')
            ->has('tenants'));

        $tenants = $response->inertiaProps('tenants');

        $this->assertContains('swecom', array_column($tenants, 'id'));
    }

    public function test_non_landlord_user_is_forbidden_from_the_landlord_area(): void
    {
        $this->setOwnerIsLandlord(false);

        try {
            $this->actingAs($this->owner());

            $this->get('/landlord/tenants')->assertForbidden();
            $this->get('/landlord/tenants/create')->assertForbidden();
            $this->post('/landlord/tenants', [
                'name' => 'Should Not Exist',
                'slug' => 'should-not-exist',
            ])->assertForbidden();

            $this->assertDatabaseMissing('tenants', ['id' => 'should-not-exist']);
        } finally {
            $this->setOwnerIsLandlord(true);
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/landlord/tenants')->assertRedirect('/login');
    }

    public function test_store_provisions_a_working_tenant(): void
    {
        $this->setOwnerIsLandlord(true);

        // Unique per run so repeated test runs never collide with a
        // Postgres schema left behind by an earlier interrupted run.
        $slug = 'ztest'.now()->format('YmdHis').random_int(100, 999);

        try {
            $this->actingAs($this->owner());

            $response = $this->post('/landlord/tenants', [
                'name' => 'Test LCO Client',
                'slug' => $slug,
            ]);

            $response->assertRedirect('/landlord/tenants');
            $response->assertSessionHas('success');

            $tenant = Tenant::find($slug);
            $this->assertNotNull($tenant, 'Tenant::create() should have provisioned a working tenant.');
            $this->assertSame('Test LCO Client', $tenant->name);
            $this->assertSame($slug, $tenant->slug);
            $this->assertTrue($tenant->is_active);
        } finally {
            // Real cleanup rather than the "accept leftover schemas"
            // tradeoff: delete() runs Stancl's TenantDeleted ->
            // DeleteDatabase pipeline synchronously (shouldBeQueued(false)
            // in TenancyServiceProvider), dropping the tenant_{slug}
            // schema along with the central `tenants` row.
            Tenant::find($slug)?->delete();
        }
    }

    public function test_store_rejects_a_non_url_safe_slug(): void
    {
        $this->setOwnerIsLandlord(true);

        $this->actingAs($this->owner());

        $response = $this->post('/landlord/tenants', [
            'name' => 'Bad Slug Tenant',
            'slug' => 'not a valid slug!',
        ]);

        $response->assertSessionHasErrors(['slug']);

        // `name` isn't a real column (it lives inside the virtual
        // `data` JSON column — see App\Models\Tenant's doc comment),
        // so assertDatabaseMissing can't query it directly; check via
        // the model instead.
        $this->assertFalse(
            Tenant::all()->contains(fn (Tenant $tenant) => $tenant->name === 'Bad Slug Tenant'),
        );
    }

    public function test_landlord_can_toggle_a_tenant_active_status(): void
    {
        $this->setOwnerIsLandlord(true);

        $this->actingAs($this->owner());

        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant);

        try {
            $response = $this->patch("/landlord/tenants/{$tenant->id}", ['is_active' => '0']);

            $response->assertRedirect('/landlord/tenants');
            $this->assertFalse(Tenant::find('swecom')->is_active);
        } finally {
            // Real, committed write (Tenant is central-pinned), so it must
            // be restored explicitly rather than relying on a transaction
            // rollback — see class doc comment.
            Tenant::find('swecom')?->update(['is_active' => true]);
        }
    }

    /**
     * Provisions a real pending-registration tenant (mirroring
     * test_store_provisions_a_working_tenant's real-provisioning approach —
     * see class doc comment for why an uncommitted transaction can't be
     * used here) plus a registrant TenantUser inside it, so the approve/
     * reject flow can be proven end-to-end against
     * App\Http\Middleware\ResolveTenantWeb's registration_status gate.
     *
     * @return array{tenant: Tenant, registrant: User}
     */
    private function createPendingWorkspace(): array
    {
        $slug = 'zpending'.now()->format('YmdHis').random_int(100, 999);

        $tenant = Tenant::create([
            'id' => $slug,
            'name' => 'Pending LCO Client',
            'slug' => $slug,
            'registration_status' => 'pending',
        ]);

        $registrant = User::factory()->create();

        tenancy()->initialize($tenant);
        TenantUser::create(['user_id' => $registrant->id, 'tenant_id' => $tenant->id, 'role' => 'super']);
        tenancy()->end();

        return ['tenant' => $tenant, 'registrant' => $registrant];
    }

    /**
     * Real cleanup mirroring test_store_provisions_a_working_tenant: drops
     * the tenant schema via Tenant::delete(), then removes the
     * TenantUserIndex row and registrant User row that a schema drop alone
     * would leave behind (dropping the schema doesn't run a DELETE on the
     * tenant_users row, so TenantUser's deleted() sync hook never fires).
     */
    private function cleanupPendingWorkspace(Tenant $tenant, User $registrant): void
    {
        Tenant::find($tenant->id)?->delete();
        TenantUserIndex::query()->where('user_id', $registrant->id)->where('tenant_id', $tenant->id)->delete();
        $registrant->delete();
    }

    public function test_landlord_can_approve_a_pending_tenant_and_unblock_its_registrant(): void
    {
        $this->setOwnerIsLandlord(true);

        ['tenant' => $tenant, 'registrant' => $registrant] = $this->createPendingWorkspace();

        try {
            // Before approval: ResolveTenantWeb sends the registrant to the
            // holding page instead of their dashboard.
            $this->actingAs($registrant)
                ->get('/dashboard')
                ->assertRedirect('/workspace/pending');

            $this->actingAs($this->owner());

            $response = $this->post("/landlord/tenants/{$tenant->id}/approve");

            $response->assertRedirect('/landlord/tenants');
            $response->assertSessionHas('success');

            $this->assertSame('approved', Tenant::find($tenant->id)->registration_status);

            // After approval, same session, no re-login — the gate is
            // re-checked per-request by ResolveTenantWeb, not baked into
            // the session at login time.
            $this->actingAs($registrant)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
        } finally {
            $this->cleanupPendingWorkspace($tenant, $registrant);
        }
    }

    public function test_landlord_can_reject_a_pending_tenant_with_a_reason(): void
    {
        $this->setOwnerIsLandlord(true);

        ['tenant' => $tenant, 'registrant' => $registrant] = $this->createPendingWorkspace();

        try {
            $this->actingAs($this->owner());

            $response = $this->post("/landlord/tenants/{$tenant->id}/reject", [
                'reason' => 'Company details could not be verified.',
            ]);

            $response->assertRedirect('/landlord/tenants');
            $response->assertSessionHas('success');

            $fresh = Tenant::find($tenant->id);
            $this->assertSame('rejected', $fresh->registration_status);
            $this->assertSame('Company details could not be verified.', $fresh->rejection_reason);

            // Still blocked, now on the rejected branch of the same gate.
            $this->actingAs($registrant)
                ->get('/dashboard')
                ->assertRedirect('/workspace/pending');
        } finally {
            $this->cleanupPendingWorkspace($tenant, $registrant);
        }
    }

    public function test_non_landlord_is_forbidden_from_approving_or_rejecting_tenants(): void
    {
        ['tenant' => $tenant, 'registrant' => $registrant] = $this->createPendingWorkspace();

        $this->setOwnerIsLandlord(false);

        try {
            $this->actingAs($this->owner());

            $this->post("/landlord/tenants/{$tenant->id}/approve")->assertForbidden();
            $this->post("/landlord/tenants/{$tenant->id}/reject")->assertForbidden();

            $this->assertSame('pending', Tenant::find($tenant->id)->registration_status);
        } finally {
            $this->cleanupPendingWorkspace($tenant, $registrant);
            $this->setOwnerIsLandlord(true);
        }
    }

    public function test_bulk_whatsapp_entitlement_defaults_false_and_landlord_can_toggle_it(): void
    {
        $this->setOwnerIsLandlord(true);

        $this->actingAs($this->owner());

        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant);
        $this->assertFalse(
            $tenant->bulk_whatsapp_enabled,
            'A tenant with no bulk_whatsapp_enabled key in `data` should default to NOT entitled (opt-in, unlike is_active).'
        );

        try {
            $response = $this->patch("/landlord/tenants/{$tenant->id}", ['bulk_whatsapp_enabled' => '1']);

            $response->assertRedirect('/landlord/tenants');
            $this->assertTrue(Tenant::find('swecom')->bulk_whatsapp_enabled);

            // The is_active flag is untouched by a request that only
            // carries bulk_whatsapp_enabled — each toggle is its own <Form>
            // on the Edit page and only writes the field it submitted.
            $this->assertTrue(Tenant::find('swecom')->is_active);
        } finally {
            Tenant::find('swecom')?->update(['bulk_whatsapp_enabled' => false]);
        }
    }

    public function test_non_landlord_cannot_toggle_bulk_whatsapp_entitlement(): void
    {
        $this->setOwnerIsLandlord(false);

        try {
            $this->actingAs($this->owner());

            $tenant = Tenant::find('swecom');

            $this->patch("/landlord/tenants/{$tenant->id}", ['bulk_whatsapp_enabled' => '1'])->assertForbidden();

            $this->assertFalse(Tenant::find('swecom')->bulk_whatsapp_enabled);
        } finally {
            $this->setOwnerIsLandlord(true);
        }
    }

    public function test_index_filters_tenants_by_registration_status(): void
    {
        $this->setOwnerIsLandlord(true);

        ['tenant' => $tenant, 'registrant' => $registrant] = $this->createPendingWorkspace();

        try {
            $this->actingAs($this->owner());

            $response = $this->get('/landlord/tenants?status=pending');

            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Tenants/Index')
                ->has('tenants'));

            $tenants = $response->inertiaProps('tenants');

            $this->assertContains($tenant->id, array_column($tenants, 'id'));
            $this->assertNotContains('swecom', array_column($tenants, 'id'));
        } finally {
            $this->cleanupPendingWorkspace($tenant, $registrant);
        }
    }
}
