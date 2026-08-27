<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Factories\BudgetFactory;
use Database\Factories\ExpenditureFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via
 * ExpenseCategoryFactory/ExpenditureFactory; none of the real seeded rows
 * are touched (except that ExpenditureFactory-created rows always point
 * `user_id` at the already-committed seeded owner — see seededUserId()'s
 * doc comment for why).
 */
class ExpenditureTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // ResourcesDashboardService caches its payload per-period, branch
        // suffixed, behind Cache::remember()
        // ('resources:dashboard:{period}:{branchId|"all"}'). Every test in
        // this class authenticates via tokenForRole() with no $branchId
        // argument, which resolves to the unrestricted 'all' key. The
        // testing cache store is 'array', which persists for the whole
        // PHPUnit process rather than resetting between
        // tests/DatabaseTransactions rollbacks — so a dashboard fetched in
        // one test can leak a stale, cached response into a later test that
        // fetches the same (typically "current month") period. Forgetting
        // the current period's key here gives every test in this class a
        // clean read.
        Cache::forget('resources:dashboard:'.now()->format('Y-m').':all');
    }

    /**
     * expenditures.user_id is a cross-schema FK into the central
     * public.users table (a different Postgres session than the `tenant`
     * connection Expenditure itself is created on — see
     * InteractsWithTenantRoles's class doc for the identical problem with
     * tenant_users). ExpenditureFactory's default `user_id => UserFactory::
     * new()` would create a brand-new User row inside this test's *own*,
     * still-uncommitted `pgsql` transaction — invisible to the `tenant`
     * session's FK check. Reusing the already-committed seeded owner
     * (kelvin@shalomtech.dev) sidesteps that entirely, exactly like
     * InteractsWithTenantRoles::tokenForRole() does for tenant_users.
     */
    private function seededUserId(): int
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail()->id;
    }

    public function test_index_lists_expenditures_filtered_by_category(): void
    {
        $userId = $this->seededUserId();
        $category = ExpenseCategoryFactory::new()->create();
        $otherCategory = ExpenseCategoryFactory::new()->create();

        ExpenditureFactory::new()->create(['category_id' => $category->id, 'user_id' => $userId]);
        ExpenditureFactory::new()->create(['category_id' => $otherCategory->id, 'user_id' => $userId]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/resources/expenditures?category_uuid={$category->uuid}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($category->uuid, $data[0]['category_uuid']);
    }

    public function test_super_can_record_an_expenditure_which_resolves_the_category_uuid(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/resources/expenditures', [
                'category_uuid' => $category->uuid,
                'amount' => 1500,
                'description' => 'Fuel for zone rounds',
                'spent_at' => now()->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.category_uuid', $category->uuid)
            ->assertJsonPath('data.amount', '1500.00');
    }

    public function test_agent_can_record_an_expenditure(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/resources/expenditures', [
                'category_uuid' => $category->uuid,
                'amount' => 2000,
                'spent_at' => now()->toDateString(),
            ]);

        $response->assertCreated();
    }

    public function test_worker_cannot_record_an_expenditure(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/resources/expenditures', [
                'category_uuid' => $category->uuid,
                'amount' => 2000,
                'spent_at' => now()->toDateString(),
            ]);

        $response->assertStatus(403);
    }

    public function test_store_fails_validation_for_zero_amount(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/resources/expenditures', [
                'category_uuid' => $category->uuid,
                'amount' => 0,
                'spent_at' => now()->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_store_fails_for_an_unknown_category_uuid(): void
    {
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/resources/expenditures', [
                'category_uuid' => (string) Str::uuid(),
                'amount' => 1000,
                'spent_at' => now()->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['category_uuid']);
    }

    public function test_manager_can_update_but_not_delete_an_expenditure(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $expenditure = ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 1000,
        ]);

        $token = $this->tokenForRole('manager');

        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/resources/expenditures/{$expenditure->uuid}", ['amount' => 2000]);

        $updateResponse->assertStatus(403);

        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/resources/expenditures/{$expenditure->uuid}");

        $deleteResponse->assertStatus(403);
    }

    public function test_super_can_update_and_delete_an_expenditure(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $expenditure = ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 1000,
        ]);

        $token = $this->tokenForRole('super');

        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/resources/expenditures/{$expenditure->uuid}", ['amount' => 2500]);

        $updateResponse->assertOk()->assertJsonPath('data.amount', '2500.00');

        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/resources/expenditures/{$expenditure->uuid}");

        $deleteResponse->assertOk()->assertJson(['message' => 'Expenditure deleted']);
        $this->assertDatabaseMissing('expenditures', ['id' => $expenditure->id]);
    }

    public function test_dashboard_requires_super_admin_or_manager_role(): void
    {
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard');

        $response->assertStatus(403);
    }

    public function test_dashboard_returns_income_expenses_and_pnl_for_the_period(): void
    {
        $period = now()->format('Y-m');
        $category = ExpenseCategoryFactory::new()->create(['name' => 'Field Operations']);

        ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 1500,
            'spent_at' => now()->startOfMonth()->addDays(2),
        ]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard');

        $response->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('expenses.total', '1500.00')
            ->assertJsonPath('expenses.by_category.0.name', 'Field Operations')
            ->assertJsonStructure([
                'period',
                'income' => ['total', 'verified', 'pending_verification', 'rejected', 'payment_count'],
                'expenses' => ['total', 'by_category'],
                'pnl' => ['net', 'margin_pct'],
                'budgets',
            ]);
    }

    /**
     * Regression test for the P&L "income" figure: net/margin must be
     * derived from *verified* payments only (api-spec.md 6.5 documents
     * verified/pending_verification/rejected as distinct figures). Uses
     * before/after deltas rather than asserting the raw totals because this
     * runs against the real tenant schema, which may already carry
     * unrelated payments for the current month.
     */
    public function test_pnl_net_reflects_only_verified_income_not_pending_or_rejected_payments(): void
    {
        $period = now()->format('Y-m');
        $token = $this->tokenForRole('super');

        Cache::forget('resources:dashboard:'.$period.':all');
        $before = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period)
            ->assertOk()
            ->json();

        PaymentFactory::new()->create(['amount' => 4000, 'verification_status' => 'verified']);
        PaymentFactory::new()->pending()->create(['amount' => 1500]);
        PaymentFactory::new()->rejected()->create(['amount' => 2500]);

        Cache::forget('resources:dashboard:'.$period.':all');
        $after = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period)
            ->assertOk()
            ->json();

        $this->assertEqualsWithDelta(4000.0, (float) $after['income']['verified'] - (float) $before['income']['verified'], 0.01);
        $this->assertEqualsWithDelta(1500.0, (float) $after['income']['pending_verification'] - (float) $before['income']['pending_verification'], 0.01);
        $this->assertEqualsWithDelta(2500.0, (float) $after['income']['rejected'] - (float) $before['income']['rejected'], 0.01);
        $this->assertEqualsWithDelta(8000.0, (float) $after['income']['total'] - (float) $before['income']['total'], 0.01);

        // The core check: net must move by the *verified* delta (4000)
        // only — never the raw total delta (8000), which would silently
        // count pending/rejected payments as real income/profit.
        $this->assertEqualsWithDelta(4000.0, (float) $after['pnl']['net'] - (float) $before['pnl']['net'], 0.01);
    }

    /**
     * A month where expenses exceed income is a genuine loss. The dashboard
     * must render it without crashing or dividing by zero, and net/margin
     * must move in the expected (negative) direction.
     */
    public function test_dashboard_handles_a_loss_period_without_crashing_or_dividing_by_zero(): void
    {
        $period = now()->format('Y-m');
        $token = $this->tokenForRole('super');

        Cache::forget('resources:dashboard:'.$period.':all');
        $before = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period)
            ->assertOk()
            ->json();

        $category = ExpenseCategoryFactory::new()->create(['name' => 'ZZ Test Loss Category']);

        ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 900000,
            'spent_at' => now(),
        ]);

        Cache::forget('resources:dashboard:'.$period.':all');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period);

        $response->assertOk();
        $after = $response->json();

        $this->assertEqualsWithDelta(900000.0, (float) $after['expenses']['total'] - (float) $before['expenses']['total'], 0.01);
        $this->assertEqualsWithDelta(-900000.0, (float) $after['pnl']['net'] - (float) $before['pnl']['net'], 0.01);
        $this->assertTrue(is_numeric($after['pnl']['margin_pct']), 'margin_pct must be a sensible numeric value, not NAN/INF/null.');
    }

    /**
     * api-spec.md 6.6 documents category delete as a soft "deactivate", not
     * a hard delete — deactivating must stop the category being offered for
     * *new* expenditures but must never retroactively hide the historical
     * spend already recorded under it from past-period P&L reporting.
     */
    public function test_expense_breakdown_still_includes_spend_from_a_deactivated_category(): void
    {
        $period = now()->format('Y-m');

        $category = ExpenseCategoryFactory::new()->create(['name' => 'ZZ Test Deactivate Me']);

        ExpenditureFactory::new()->create([
            'category_id' => $category->id,
            'user_id' => $this->seededUserId(),
            'amount' => 6000,
            'spent_at' => now(),
        ]);

        $token = $this->tokenForRole('admin');

        // Deactivate *after* the historical spend was recorded.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/resources/categories/{$category->uuid}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        Cache::forget('resources:dashboard:'.$period.':all');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period);

        $response->assertOk();

        $row = collect($response->json('expenses.by_category'))->firstWhere('name', 'ZZ Test Deactivate Me');

        $this->assertNotNull($row, 'Historical spend under a deactivated category must still appear in the P&L breakdown.');
        $this->assertSame('6000.00', $row['amount']);
        $this->assertSame(1, $row['count']);
    }

    /**
     * api-spec.md 6.5's example shows variance = budgeted - actual with a
     * POSITIVE variance meaning under-budget (budgeted 150,000, actual
     * 120,000, variance +30,000). Also verifies a category with no budget
     * row for the period is omitted from the section entirely rather than
     * shown with a nonsensical "0 budgeted" variance.
     */
    public function test_budget_variance_uses_correct_sign_and_omits_categories_without_a_budget(): void
    {
        $period = now()->format('Y-m');

        $budgetedCategory = ExpenseCategoryFactory::new()->create(['name' => 'ZZ Test Budgeted Category']);
        $unbudgetedCategory = ExpenseCategoryFactory::new()->create(['name' => 'ZZ Test Unbudgeted Category']);

        BudgetFactory::new()->forPeriod($period)->create([
            'category_id' => $budgetedCategory->id,
            'amount' => 150000,
        ]);

        ExpenditureFactory::new()->create([
            'category_id' => $budgetedCategory->id,
            'user_id' => $this->seededUserId(),
            'amount' => 120000,
            'spent_at' => now(),
        ]);

        ExpenditureFactory::new()->create([
            'category_id' => $unbudgetedCategory->id,
            'user_id' => $this->seededUserId(),
            'amount' => 50000,
            'spent_at' => now(),
        ]);

        $token = $this->tokenForRole('super');

        Cache::forget('resources:dashboard:'.$period.':all');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/dashboard?period='.$period);

        $response->assertOk();

        $budgets = collect($response->json('budgets'));
        $row = $budgets->firstWhere('category', 'ZZ Test Budgeted Category');

        $this->assertNotNull($row, 'A category with a budget row for the period must appear in the budgets section.');
        $this->assertSame('150000.00', $row['budgeted']);
        $this->assertSame('120000.00', $row['actual']);
        $this->assertSame('30000.00', $row['variance']);
        $this->assertEqualsWithDelta(20.0, $row['variance_pct'], 0.1);

        $this->assertNull(
            $budgets->firstWhere('category', 'ZZ Test Unbudgeted Category'),
            'A category with no budget row for the period must not appear in the budgets section at all.'
        );
    }

    public function test_categories_index_lists_active_and_inactive_categories(): void
    {
        // The real tenant schema already carries the 9 seeded reference
        // categories (see TenantDatabaseSeeder) — assert the two new
        // fixtures are present in the response rather than the total count.
        ExpenseCategoryFactory::new()->create(['name' => 'Test Utilities Category']);
        ExpenseCategoryFactory::new()->inactive()->create(['name' => 'Test Old Category']);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/resources/categories');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Test Utilities Category', $names);
        $this->assertContains('Test Old Category', $names);
    }

    public function test_only_admin_can_create_a_category(): void
    {
        $agentToken = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/resources/categories', ['name' => 'New Category']);

        $response->assertStatus(403);

        $adminToken = $this->tokenForRole('admin');

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/v1/resources/categories', ['name' => 'New Category']);

        $response->assertCreated()->assertJsonPath('data.name', 'New Category');
    }

    public function test_admin_can_deactivate_a_category_instead_of_hard_deleting_it(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $token = $this->tokenForRole('admin');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/resources/categories/{$category->uuid}");

        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    }
}
