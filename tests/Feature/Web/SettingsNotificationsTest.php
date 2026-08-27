<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\NotificationSetting;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings — Notifications (bill-notifications.md sections 3 and 6.1-6.2).
 * Same session-auth Inertia pattern as SettingsTest: reuse the real seeded
 * owner (kelvin@shalomtech.dev), flipping their tenant_users role per test.
 */
class SettingsNotificationsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // NotificationSetting::cached() is process-cache-backed (see its doc
        // comment) — forget it before every test so each test starts from a
        // clean firstOrCreate() rather than a stale row from an earlier test
        // in the same run.
        Cache::forget(NotificationSetting::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(NotificationSetting::CACHE_KEY);

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_page_renders_with_the_auto_created_single_row(): void
    {
        $this->actingAsRole('admin');

        $response = $this->get('/settings/notifications');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Notifications')
                ->has('settings')
                ->where('settings.whatsapp_enabled', false)
                ->where('settings.email_enabled', false)
                ->where('settings.sms_enabled', false)
                ->where('bulk_whatsapp_entitled', false));
    }

    public function test_twilio_fields_are_reported_hidden_until_the_landlord_entitlement_is_on(): void
    {
        $this->actingAsRole('admin');

        $notEntitled = $this->get('/settings/notifications');
        $notEntitled->assertInertia(fn (Assert $page) => $page->where('bulk_whatsapp_entitled', false));

        // Flip the landlord-controlled entitlement (Tenant::bulkWhatsappEnabled)
        // on the SAME tenant object instance tenancy() is already holding
        // (tenancy()->tenant, what the `tenant()` helper resolves to), not
        // a separately re-fetched Tenant::find('swecom'). Stancl's
        // Tenancy::initialize() no-ops when asked to initialize a tenant
        // with the same key it's already initialized to (see
        // vendor/stancl/tenancy/src/Tenancy.php) — since setUp() already
        // initialized tenancy for this test, ResolveTenantWeb's per-request
        // re-initialize on the next request would otherwise silently keep
        // serving the stale pre-update object. No manual restore needed:
        // DatabaseTransactions wraps the central `pgsql` connection this
        // write lands on, rolled back automatically at teardown.
        tenancy()->tenant->update(['bulk_whatsapp_enabled' => true]);

        $entitled = $this->get('/settings/notifications');
        $entitled->assertInertia(fn (Assert $page) => $page->where('bulk_whatsapp_entitled', true));
    }

    public function test_admin_can_update_channel_toggles(): void
    {
        $this->actingAsRole('admin');

        $response = $this->patch('/settings/notifications', [
            'whatsapp_enabled' => '1',
            'email_enabled' => '0',
            'sms_enabled' => '1',
        ]);

        $response->assertRedirect(route('settings.notifications.edit'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('notification_settings', [
            'whatsapp_enabled' => true,
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);
    }

    public function test_manager_cannot_update_notification_settings(): void
    {
        $this->actingAsRole('manager');

        $response = $this->patch('/settings/notifications', [
            'whatsapp_enabled' => '1',
            'email_enabled' => '0',
            'sms_enabled' => '0',
        ]);

        $response->assertStatus(403);
    }

    public function test_agent_can_still_view_notification_settings(): void
    {
        $this->actingAsRole('agent');

        $this->get('/settings/notifications')->assertOk();
    }

    /**
     * Proves the Twilio credentials round-trip through the real update
     * endpoint AND that the raw Postgres column never holds plaintext —
     * reads the column directly via DB::connection('tenant'), bypassing
     * Eloquent's decrypting accessor entirely, rather than just trusting
     * the `encrypted` cast is configured correctly.
     */
    public function test_twilio_credentials_are_encrypted_at_rest_not_plaintext(): void
    {
        $this->actingAsRole('admin');

        $response = $this->patch('/settings/notifications', [
            'whatsapp_enabled' => '1',
            'email_enabled' => '0',
            'sms_enabled' => '0',
            'twilio_account_sid' => 'ACfeaturetest1234567890',
            'twilio_auth_token' => 'super-secret-auth-token-value',
            'twilio_whatsapp_number' => '+14155238886',
        ]);

        $response->assertRedirect(route('settings.notifications.edit'));

        $row = DB::connection('tenant')->table('notification_settings')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('ACfeaturetest1234567890', (string) $row->twilio_account_sid);
        $this->assertStringNotContainsString('super-secret-auth-token-value', (string) $row->twilio_auth_token);
        $this->assertStringNotContainsString('+14155238886', (string) $row->twilio_whatsapp_number);

        // But the model's decrypting accessor gives back the real values.
        Cache::forget(NotificationSetting::CACHE_KEY);
        $fresh = NotificationSetting::query()->first();
        $this->assertSame('ACfeaturetest1234567890', $fresh->twilio_account_sid);
        $this->assertSame('super-secret-auth-token-value', $fresh->twilio_auth_token);
        $this->assertSame('+14155238886', $fresh->twilio_whatsapp_number);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/notifications')->assertRedirect('/login');
    }
}
