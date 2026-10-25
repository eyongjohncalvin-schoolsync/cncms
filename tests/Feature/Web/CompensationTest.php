<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompensationBatch;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\FreeMonthGrant;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Compensation for poor service (owner, 2026-10-25): a manager may give one
 * customer a few free months a year on their own; beyond that, and for a
 * whole zone or branch, an admin other than whoever asked approves.
 */
class CompensationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Service $tv;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->zone = ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()]);
        $this->allowance(1);
    }

    public function test_a_manager_gives_a_month_within_the_allowance_straight_away(): void
    {
        $customer = $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager');

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'No signal for a week'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Free months given'));

        $grant = $this->grantsOf($customer)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
        $this->assertNotNull($grant->start_period);
        $this->assertSame($grant->requested_by, $grant->decided_by);
    }

    public function test_beyond_the_yearly_allowance_an_admin_approves(): void
    {
        $customer = $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Outage'])->assertSessionHasNoErrors();

        // The year's allowance is used: the next month waits.
        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Another outage'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->grantsOf($customer)->where('status', FreeMonthGrant::STATUS_PENDING_APPROVAL)->count());

        // More months than the allowance in one go waits too.
        $other = $this->subscriber();
        $this->post("/customers/{$other->uuid}/free-months", ['months' => 2, 'reason' => 'Long outage'])->assertSessionHasNoErrors();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $this->grantsOf($other)->sole()->status);

        $this->get("/customers/{$customer->uuid}")->assertInertia(fn (Assert $page) => $page->where('customer.free_months.months_without_approval_left', 0));
    }

    public function test_an_allowance_of_zero_always_asks(): void
    {
        $this->allowance(0);
        $customer = $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager');

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Outage'])->assertSessionHasNoErrors();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $this->grantsOf($customer)->sole()->status);
    }

    public function test_a_zone_is_compensated_with_one_approval_by_someone_else(): void
    {
        $first = $this->subscriber();
        $second = $this->subscriber();
        $cutOff = $this->subscriber(['status' => 'disconnected']);
        $elsewhere = $this->subscriber([], ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()]));

        $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post('/compensation', $this->batchForm(['zone_uuid' => $this->zone->uuid]))->assertSessionHasNoErrors();

        $batch = CompensationBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $batch->customers_count, 'Only the zone\'s customers who were receiving the service.');
        $this->assertSame(0, $this->grantsOf($cutOff)->count() + $this->grantsOf($elsewhere)->count());
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $this->grantsOf($first)->sole()->status, 'Never within the allowance.');

        // Whoever asked can't approve it…
        $this->post("/compensation/{$batch->uuid}/approve")->assertForbidden();

        // …an admin approves once, for everyone.
        $this->as('patience@shalomtech.dev', 'admin');
        $this->post("/compensation/{$batch->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame(CompensationBatch::STATUS_APPROVED, $batch->fresh()->status);
        foreach ([$first, $second] as $customer) {
            $grant = $this->grantsOf($customer)->sole();
            $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
            $this->assertNotNull($grant->start_period);
        }
    }

    public function test_rejecting_a_branch_wide_compensation_rejects_every_customer_in_it(): void
    {
        $customer = $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post('/compensation', $this->batchForm())->assertSessionHasNoErrors();
        $batch = CompensationBatch::query()->latest('id')->firstOrFail();
        $this->assertNull($batch->zone_id, 'The whole branch.');

        $this->as('patience@shalomtech.dev', 'admin');
        $this->post("/compensation/{$batch->uuid}/reject", ['reason' => 'The outage was one day'])->assertSessionHasNoErrors();

        $this->assertSame(CompensationBatch::STATUS_REJECTED, $batch->fresh()->status);
        $this->assertSame(FreeMonthGrant::STATUS_REJECTED, $this->grantsOf($customer)->sole()->status);
    }

    public function test_a_branch_manager_only_compensates_their_own_branch(): void
    {
        $town = BranchFactory::new()->create();
        $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager', $town->id);

        $this->post('/compensation', $this->batchForm())->assertSessionHasErrors('branch');
        $this->assertSame(0, CompensationBatch::query()->count());
    }

    public function test_the_page_shows_what_waits_and_what_it_costs(): void
    {
        $customer = $this->subscriber();
        $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post('/compensation', $this->batchForm(['zone_uuid' => $this->zone->uuid]))->assertSessionHasNoErrors();
        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Outage'])->assertSessionHasNoErrors();

        $this->as('patience@shalomtech.dev', 'admin');
        $this->get('/compensation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Compensation/Index')
            ->where('waiting.batches.0.customers_count', 1)
            ->where('waiting.batches.0.can_decide', true)
            ->where('given.customers.0.without_approval', true)
            ->has('cost.total'));
    }

    public function test_the_allowance_is_set_on_settings(): void
    {
        $this->as('patience@shalomtech.dev', 'admin');

        $this->patch('/settings/installation-offers/cap', ['max_free_months' => 3, 'compensation_months_without_approval' => 2])->assertSessionHasNoErrors();

        $this->assertSame(2, Company::query()->firstOrFail()->compensation_months_without_approval);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function batchForm(array $extra = []): array
    {
        return [
            'branch_uuid' => Branch::query()->whereKey(Branch::headquartersId())->value('uuid'),
            'months' => 1,
            'note' => 'Signal outage 3–10 October',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscriber(array $attributes = [], ?Zone $zone = null): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ($zone ?? $this->zone)->id, 'bill' => 2500, 'status' => 'active', ...$attributes]);
        CustomerSubscription::query()->create(['customer_id' => $customer->id, 'service_id' => $this->tv->id, 'price' => 2500]);

        return $customer;
    }

    /**
     * @return Builder<FreeMonthGrant>
     */
    private function grantsOf(Customer $customer): Builder
    {
        return FreeMonthGrant::query()->where('customer_id', $customer->id)->where('reason', FreeMonthGrant::REASON_COMPENSATION);
    }

    private function allowance(int $months): void
    {
        Company::query()->firstOrFail()->update(['compensation_months_without_approval' => $months]);
        Company::forgetCache();
    }

    private function as(string $email, string $role, ?int $branchId = null): void
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }
}
