<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /manuscripts/runs/{run} — App\Http\Controllers\ManuscriptController::
 * runReview(), the new one-click "watch it compute, then review and publish"
 * screen ManuscriptController::calculate() now redirects to
 * (task-scheduler.md's 2026-08-27 stage 3 addendum).
 */
class ManuscriptRunReviewTest extends TestCase
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

    private function pendingReviewRun(string $period): CommandRun
    {
        return CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => 'swecom', 'trigger' => 'manual'],
            'status' => 'pending_review',
            'computed_result' => [
                'customers' => [],
                'summary' => [
                    'customers_processed' => 5,
                    'frozen_customers' => 1,
                    'total_arrears_sum' => 1000.0,
                    'total_credit_sum' => 0.0,
                    'total_bill_sum' => 5000.0,
                    'errors' => 0,
                    'error_details' => [],
                ],
            ],
        ]);
    }

    public function test_an_admin_can_view_the_run_review_screen(): void
    {
        $this->actingAsRole('admin');
        $run = $this->pendingReviewRun('2033-01');

        $response = $this->get("/manuscripts/runs/{$run->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manuscripts/RunReview')
                ->where('run.uuid', $run->uuid)
                ->where('run.period', '2033-01')
                ->where('run.status', 'pending_review')
                ->where('run.computed_result_summary.customers_processed', 5)
                ->where('canPublish', true));
    }

    public function test_the_review_screen_surfaces_the_per_customer_computed_preview(): void
    {
        $this->actingAsRole('admin');

        $customers = Customer::query()->limit(2)->get();
        $this->assertCount(2, $customers, 'swecom must have at least 2 customers for this test');

        $run = CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => '2033-05',
            'ran_at' => now(),
            'metadata' => ['tenant' => 'swecom', 'trigger' => 'manual'],
            'status' => 'pending_review',
            'computed_result' => [
                'summary' => ['customers_processed' => 2, 'errors' => 0, 'error_details' => []],
                'customers' => [
                    (string) $customers[0]->id => [
                        'is_frozen' => false,
                        'attributes' => ['bill' => '2500.00', 'total_arrears' => '5000.00', 'credit' => '0.00', 'total_bill' => '7500.00', 'prepaid_months_remaining' => 0, 'payment_expiration' => null, 'prepaid_rate' => null],
                        'processed_payment_ids' => [],
                        'processed_adjustment_ids' => [],
                    ],
                    (string) $customers[1]->id => [
                        'is_frozen' => true,
                        'attributes' => ['bill' => '3000.00', 'total_arrears' => '0.00', 'credit' => '0.00', 'total_bill' => '0.00', 'prepaid_months_remaining' => 0, 'payment_expiration' => null, 'prepaid_rate' => null],
                        'processed_payment_ids' => [111, 222],
                        'processed_adjustment_ids' => [],
                    ],
                ],
            ],
        ]);

        $this->get("/manuscripts/runs/{$run->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manuscripts/RunReview')
                ->has('computed_rows', 2)
                ->where('computed_rows.0.customer_name', fn ($v) => is_string($v) && $v !== '')
                ->where('computed_rows.0.total_bill', fn ($v) => in_array($v, ['7500.00', '0.00'], true))
                ->where('computed_rows.1.payments_applied', fn ($v) => in_array($v, [0, 2], true)));
    }

    public function test_a_manager_cannot_view_the_run_review_screen(): void
    {
        $this->actingAsRole('manager');
        $run = $this->pendingReviewRun('2033-02');

        $this->get("/manuscripts/runs/{$run->uuid}")->assertForbidden();
    }

    /**
     * This route is the manual Calculate trigger's own follow-through screen,
     * not a general command-run viewer — a stray uuid for a differently-named
     * command (there are none today, but the guard exists for when there
     * are) must 404 rather than render.
     */
    public function test_a_run_for_a_different_command_404s(): void
    {
        $this->actingAsRole('admin');
        $run = CommandRun::create([
            'command' => 'some:other-command',
            'period' => '2033-03',
            'ran_at' => now(),
            'metadata' => [],
            'status' => 'pending_review',
        ]);

        $this->get("/manuscripts/runs/{$run->uuid}")->assertNotFound();
    }
}
