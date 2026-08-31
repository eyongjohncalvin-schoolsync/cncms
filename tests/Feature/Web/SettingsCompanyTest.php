<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings — Company Info: the Contact & Support section, specifically the
 * "Technical Support Number" (companies.tech_number) field.
 *
 * Regression: Settings/Company.tsx used to conditionally render (unmount)
 * each tab's <Card>, so Inertia's <Form> only serialized the currently
 * visible section. Saving from the "Contact & Support" tab therefore POSTed
 * a partial payload (email/phone/tech_number only) and 422'd on the
 * untouched `required` fields (name, location, reconnection_fine,
 * arrears_second_approval_threshold) — the owner saw "name is required"
 * with no visible offending field and Save did nothing. The fix keeps every
 * section mounted (toggled with `hidden`) so the full company payload is
 * always submitted, matching Settings/Notifications & Settings/BillPrinting.
 *
 * Same session-auth Inertia pattern as SettingsTest: reuse the real seeded
 * owner (kelvin@shalomtech.dev), flipping their tenant_users role per test.
 */
class SettingsCompanyTest extends TestCase
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

    /**
     * The full company payload the fixed Settings/Company.tsx form submits
     * on every Save, regardless of which tab is active. `$overrides` lets a
     * test change just the field(s) it cares about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'SWECOM PLC',
            'location' => '3/CORNERS',
            'head_office' => 'Behind City Hall, Kumba 3, Cameroon',
            'email' => 'contact@swecom.test',
            'phone' => '676876509/672528022',
            'tech_number' => '699112233',
            'momo_number' => '676876509',
            'momo_name' => 'KELVIN MEKUME',
            'reconnection_fine' => '2000',
            'arrears_second_approval_threshold' => '20000',
            'rccm_number' => 'RC/DLA/2019/PM/127651',
            'niu' => 'M012345678901A',
        ], $overrides);
    }

    public function test_admin_can_add_a_technical_support_number_without_touching_other_sections(): void
    {
        $this->actingAsRole('admin');

        // Start from a company with no tech number set.
        Company::query()->first()->update(['tech_number' => null]);
        Company::forgetCache();

        $response = $this->patch('/settings/company', $this->fullPayload([
            'tech_number' => '699445566',
        ]));

        $response->assertRedirect(route('settings.company.edit'));
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['tech_number' => '699445566']);
    }

    public function test_admin_can_edit_and_clear_the_technical_support_number(): void
    {
        $this->actingAsRole('admin');

        Company::query()->first()->update(['tech_number' => '600000000']);
        Company::forgetCache();

        // Edit it.
        $this->patch('/settings/company', $this->fullPayload(['tech_number' => '677889900']))
            ->assertRedirect(route('settings.company.edit'));
        $this->assertDatabaseHas('companies', ['tech_number' => '677889900']);

        // Clear it (nullable) — a full save with the field blank must persist null.
        $this->patch('/settings/company', $this->fullPayload(['tech_number' => '']))
            ->assertRedirect(route('settings.company.edit'));
        $this->assertDatabaseHas('companies', ['tech_number' => null]);
    }

    public function test_required_fields_are_still_enforced_when_absent_from_the_payload(): void
    {
        $this->actingAsRole('admin');

        // A payload missing the unrelated `required` fields (the old partial-submit
        // shape) must still 422 — the frontend fix is "always send the whole
        // payload", not "make everything optional server-side".
        $response = $this->patch('/settings/company', [
            'email' => 'contact@swecom.test',
            'phone' => '676876509',
            'tech_number' => '699112233',
        ]);

        $response->assertSessionHasErrors(['name', 'location', 'reconnection_fine', 'arrears_second_approval_threshold']);
        $this->assertDatabaseMissing('companies', ['tech_number' => '699112233']);
    }

    public function test_required_fields_are_still_enforced_when_explicitly_cleared_on_a_full_update(): void
    {
        $this->actingAsRole('admin');

        $response = $this->patch('/settings/company', $this->fullPayload([
            'name' => '',
            'reconnection_fine' => '',
        ]));

        $response->assertSessionHasErrors(['name', 'reconnection_fine']);
    }
}
