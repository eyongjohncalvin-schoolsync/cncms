<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\ExpenditureFactory;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) counterpart of
 * tests/Feature/Api/ExpenditureTest.php — same
 * ExpenditureService/ExpenseCategoryService/ResourcesDashboardService and
 * ExpenditurePolicy/ExpenseCategoryPolicy underneath, just exercised through
 * App\Http\Controllers\ResourceController's Inertia routes instead of the
 * JSON API. See tests/Feature/Web/PaymentTest.php for the shared
 * setup/role-switching conventions this reuses.
 */
class ResourceTest extends TestCase
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
     * expenditures.user_id is a cross-schema FK into the central
     * public.users table (a different Postgres session than the `tenant`
     * connection Expenditure itself is created on). ExpenditureFactory's
     * default `user_id => UserFactory::new()` would create a brand-new User
     * row inside this test's own, still-uncommitted `pgsql` transaction —
     * invisible to the `tenant` session's FK check. Reusing the
     * already-committed seeded owner sidesteps that, same as
     * actingAsRole() above.
     */
    private function seededUserId(): int
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail()->id;
    }

    public function test_dashboard_renders_pnl_data_for_a_manager(): void
    {
        $category = ExpenseCategoryFactory::new()->create(['name' => 'Utilities']);
        ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 3000,
            'spent_at' => now(),
        ]);

        $this->actingAsRole('manager');

        $response = $this->get('/resources');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resources/Dashboard')
                ->has('income')
                ->has('expenses')
                ->has('pnl')
                ->has('budgets')
                ->where('expenses.total', '3000.00'));
    }

    public function test_dashboard_is_forbidden_for_an_agent(): void
    {
        $this->actingAsRole('agent');

        $response = $this->get('/resources');

        $response->assertStatus(403);
    }

    public function test_expenditures_index_renders_with_filters(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        ExpenditureFactory::new()->create(['category_id' => $category->id, 'user_id' => $this->seededUserId()]);

        $this->actingAsRole('super');

        $response = $this->get('/resources/expenditures');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resources/Expenditures/Index')
                ->has('expenditures.data')
                ->has('categories'));
    }

    /**
     * E1 (docs/plans/expenditures-and-spending-controls.md): there is no
     * direct "Record expense" any more — every cash-out is a bon.
     */
    public function test_expenditures_can_no_longer_be_recorded_directly(): void
    {
        $category = ExpenseCategoryFactory::new()->create();

        foreach (['agent', 'super'] as $role) {
            $this->actingAsRole($role);

            $this->post('/resources/expenditures', [
                'category_uuid' => $category->uuid,
                'amount' => 1500,
                'spent_at' => now()->toDateString(),
                'description' => 'Fuel',
            ])->assertStatus(405);

            $this->get('/resources/expenditures/create')->assertNotFound();
        }

        $this->assertDatabaseMissing('expenditures', ['category_id' => $category->id]);
    }

    public function test_an_expenditure_is_voided_with_a_reason_never_deleted(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $expenditure = ExpenditureFactory::new()->create(['category_id' => $category->id, 'user_id' => $patience->id, 'spent_at' => now()->toDateString()]);

        $this->actingAsRole('super');
        $this->delete("/resources/expenditures/{$expenditure->uuid}")->assertStatus(405);

        $this->actingAsRole('manager');
        $this->post("/resources/expenditures/{$expenditure->uuid}/void", ['reason' => 'Duplicate'])->assertForbidden();

        $this->actingAsRole('admin');
        $this->post("/resources/expenditures/{$expenditure->uuid}/void", [])->assertSessionHasErrors('reason');
        $this->post("/resources/expenditures/{$expenditure->uuid}/void", ['reason' => 'Duplicate'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expenditures', ['id' => $expenditure->id, 'status' => 'void', 'void_reason' => 'Duplicate']);
    }

    public function test_categories_page_renders_and_only_admin_can_create_one(): void
    {
        // The real tenant schema already carries the 9 seeded reference
        // categories (see TenantDatabaseSeeder), so this only asserts the
        // page renders with a non-empty categories prop, not an exact count.
        ExpenseCategoryFactory::new()->create(['name' => 'Test Office Category']);

        $this->actingAsRole('agent');
        $response = $this->get('/resources/categories');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Categories')
            ->has('categories'));

        $this->actingAsRole('agent');
        $this->post('/resources/categories', ['name' => 'Should Fail'])->assertStatus(403);

        $this->actingAsRole('admin');
        $response = $this->post('/resources/categories', ['name' => 'New Category']);

        $response->assertRedirect();
        $this->assertDatabaseHas('expense_categories', ['name' => 'New Category']);
    }

    public function test_admin_can_deactivate_a_category(): void
    {
        $category = ExpenseCategoryFactory::new()->create();

        $this->actingAsRole('admin');

        $response = $this->delete("/resources/categories/{$category->uuid}");

        $response->assertRedirect();
        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    }
}
