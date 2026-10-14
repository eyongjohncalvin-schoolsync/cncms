<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Notification;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\ManuscriptCalculator;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Opening balances (App\Services\OpeningBalanceService): entering paper
 * starting balances as a draft, submit → approve (maker-checker, approval
 * owner-only by default via `opening_balances.approve`), and approval
 * writing each customer's first manuscript for the month before go-live —
 * which the real ManuscriptCalculator then carries forward. Same seeded
 * swecom users / transaction setup as ArrearsAdjustmentTest.
 */
class OpeningBalanceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private string $goLive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->goLive = now()->addMonthNoOverflow()->format('Y-m');
    }

    private function actingAsSeededUser(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return Collection<int, Customer>
     */
    private function newCustomers(int $count, string $bill = '2500'): Collection
    {
        return collect(range(1, $count))->map(fn () => CustomerFactory::new()->active()->create(['bill' => $bill, 'others' => 0]));
    }

    private function draftWith(array $rows): OpeningBalanceBatch
    {
        $this->actingAsSeededUser('terence@shalomtech.dev'); // manager

        $this->post('/opening-balances', ['go_live_period' => $this->goLive])->assertRedirect();
        $batch = OpeningBalanceBatch::query()->where('status', 'draft')->latest('id')->firstOrFail();

        $this->putJson("/opening-balances/{$batch->uuid}/entries", ['rows' => $rows])->assertOk();

        return $batch;
    }

    private function submittedWith(array $rows): OpeningBalanceBatch
    {
        $batch = $this->draftWith($rows);
        $this->post("/opening-balances/{$batch->uuid}/submit")->assertSessionHas('success');

        return $batch->refresh();
    }

    public function test_a_manager_can_open_the_screen_and_sees_only_customers_without_billing_history(): void
    {
        [$fresh] = $this->newCustomers(1);
        $billed = CustomerFactory::new()->active()->create();
        ManuscriptFactory::new()->forPeriod(now()->format('Y-m'))->create(['customer_id' => $billed->id]);

        $batch = $this->draftWith([['customer_uuid' => $fresh->uuid, 'arrears' => '5000']]);

        $this->get('/opening-balances')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('OpeningBalances/Index')
            ->where('batch.uuid', $batch->uuid)
            ->where('batch.can.edit', true)
            ->where('rows', fn ($rows) => collect($rows)->contains('uuid', $fresh->uuid)
                && ! collect($rows)->contains('uuid', $billed->uuid)
                && collect($rows)->firstWhere('uuid', $fresh->uuid)['entry']['arrears'] === '5000.00'));
    }

    public function test_agents_and_workers_cannot_see_or_start_opening_balances(): void
    {
        foreach (['divine@shalomtech.dev', 'godlove@shalomtech.dev'] as $email) {
            $this->actingAsSeededUser($email);

            $this->get('/opening-balances')->assertForbidden();
            $this->post('/opening-balances', ['go_live_period' => $this->goLive])->assertForbidden();
        }
    }

    public function test_only_one_batch_can_be_in_progress(): void
    {
        $this->draftWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '0']]);

        $this->post('/opening-balances', ['go_live_period' => $this->goLive])->assertSessionHasErrors('go_live_period');
    }

    public function test_autosave_upserts_entries_and_a_fully_blank_row_removes_the_entry(): void
    {
        [$a, $b] = $this->newCustomers(2);
        $batch = $this->draftWith([
            ['customer_uuid' => $a->uuid, 'arrears' => '7500', 'note' => 'ledger p.3'],
            ['customer_uuid' => $b->uuid, 'arrears' => '0', 'advance_amount' => '0', 'advance_months' => 0],
        ]);

        $this->assertSame(2, $batch->entries()->count());

        $this->putJson("/opening-balances/{$batch->uuid}/entries", ['rows' => [
            ['customer_uuid' => $a->uuid, 'arrears' => '10000', 'note' => 'corrected'],
            ['customer_uuid' => $b->uuid, 'arrears' => null, 'advance_amount' => null, 'advance_months' => null],
        ]])->assertOk()->assertJson(['saved' => 1, 'removed' => 1]);

        $entry = OpeningBalanceEntry::query()->where('opening_balance_batch_id', $batch->id)->sole();
        $this->assertSame($a->id, $entry->customer_id);
        $this->assertSame('10000.00', (string) $entry->arrears);
        $this->assertSame('corrected', $entry->note);
    }

    public function test_autosave_rejects_negative_amounts_and_customers_with_billing_history(): void
    {
        [$fresh] = $this->newCustomers(1);
        $batch = $this->draftWith([['customer_uuid' => $fresh->uuid, 'arrears' => '1000']]);

        $this->putJson("/opening-balances/{$batch->uuid}/entries", ['rows' => [['customer_uuid' => $fresh->uuid, 'arrears' => '-5']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.arrears');

        $billed = CustomerFactory::new()->active()->create();
        ManuscriptFactory::new()->forPeriod(now()->format('Y-m'))->create(['customer_id' => $billed->id]);

        $this->putJson("/opening-balances/{$batch->uuid}/entries", ['rows' => [['customer_uuid' => $billed->uuid, 'arrears' => '1000']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_submitting_locks_editing_and_notifies_the_owner(): void
    {
        $batch = $this->submittedWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]);

        $this->assertSame('pending_approval', $batch->status);
        $this->putJson("/opening-balances/{$batch->uuid}/entries", ['rows' => [['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '1']]])
            ->assertForbidden();

        $this->assertTrue(Notification::query()
            ->where('type', 'opening_balances.submitted')
            ->where('recipient_role', 'super')
            ->where('source_uuid', $batch->uuid)
            ->exists());
    }

    public function test_an_empty_draft_cannot_be_submitted(): void
    {
        $this->actingAsSeededUser('terence@shalomtech.dev');
        $this->post('/opening-balances', ['go_live_period' => $this->goLive]);
        $batch = OpeningBalanceBatch::query()->where('status', 'draft')->latest('id')->firstOrFail();

        $this->post("/opening-balances/{$batch->uuid}/submit")->assertSessionHasErrors('batch');
    }

    public function test_by_default_only_the_owner_can_approve_and_admins_and_managers_cannot(): void
    {
        $batch = $this->submittedWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]);

        foreach (['patience@shalomtech.dev', 'terence@shalomtech.dev'] as $email) {
            $this->actingAsSeededUser($email);
            $this->post("/opening-balances/{$batch->uuid}/approve")->assertForbidden();
        }

        $this->actingAsSeededUser('kelvin@shalomtech.dev'); // super / owner
        $this->post("/opening-balances/{$batch->uuid}/approve")->assertSessionHas('success');

        $this->assertSame('approved', $batch->refresh()->status);
    }

    public function test_a_granted_approver_still_cannot_approve_their_own_submission(): void
    {
        Role::query()->where('name', 'manager')->firstOrFail()->permissions()->create(['permission' => 'opening_balances.approve']);

        $batch = $this->submittedWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]); // by the manager

        $this->post("/opening-balances/{$batch->uuid}/approve")->assertForbidden();

        // A different person holding the granted permission can.
        Role::query()->where('name', 'admin')->firstOrFail()->permissions()->create(['permission' => 'opening_balances.approve']);
        $this->actingAsSeededUser('patience@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve")->assertSessionHas('success');
    }

    public function test_approval_writes_each_customers_starting_manuscript_for_the_month_before_go_live(): void
    {
        [$owes, $paidAhead, $prepaid] = $this->newCustomers(3);
        $batch = $this->submittedWith([
            ['customer_uuid' => $owes->uuid, 'arrears' => '7500'],
            ['customer_uuid' => $paidAhead->uuid, 'advance_amount' => '5000'],
            ['customer_uuid' => $prepaid->uuid, 'advance_months' => 3],
        ]);

        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve")->assertSessionHas('success');

        $baseline = now()->format('Y-m');
        $row = fn (Customer $customer): Manuscript => Manuscript::query()->where('customer_id', $customer->id)->sole();

        $this->assertSame($baseline, $row($owes)->period);
        $this->assertSame($batch->id, $row($owes)->opening_balance_batch_id);
        $this->assertSame('7500.00', (string) $row($owes)->total_arrears);
        $this->assertSame('0.00', (string) $row($owes)->credit);

        $this->assertSame('5000.00', (string) $row($paidAhead)->credit);
        $this->assertSame('0.00', (string) $row($paidAhead)->total_bill);

        $this->assertSame(3, (int) $row($prepaid)->prepaid_months_remaining);
        $this->assertSame('2500.00', (string) $row($prepaid)->prepaid_rate);

        $this->assertTrue(Notification::query()->where('type', 'opening_balances.approved')->where('source_uuid', $batch->uuid)->exists());
    }

    /**
     * The point of the whole feature: the first real calculation for the
     * go-live month carries the approved balances forward through the
     * normal engine.
     */
    public function test_the_first_calculation_carries_the_opening_balances_forward(): void
    {
        [$owes, $paidAhead, $prepaid] = $this->newCustomers(3);
        $batch = $this->submittedWith([
            ['customer_uuid' => $owes->uuid, 'arrears' => '7500'],
            ['customer_uuid' => $paidAhead->uuid, 'advance_amount' => '5000'],
            ['customer_uuid' => $prepaid->uuid, 'advance_months' => 3],
        ]);
        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve");

        $calculate = fn (Customer $customer) => app(ManuscriptCalculator::class)->calculate(
            $customer->fresh(),
            $this->goLive,
            Manuscript::query()->where('customer_id', $customer->id)->sole(),
            collect(),
            collect(),
        );

        // Owed 7,500 + this month's 2,500.
        $this->assertSame('10000.00', $calculate($owes)->totalArrears);

        // 5,000 advance covers this month's 2,500, 2,500 credit left.
        $paidAheadResult = $calculate($paidAhead);
        $this->assertSame('0.00', $paidAheadResult->totalArrears);
        $this->assertSame('2500.00', $paidAheadResult->credit);

        // 3 prepaid months: this month is covered, 2 remain, nothing billed.
        $prepaidResult = $calculate($prepaid);
        $this->assertSame(2, $prepaidResult->prepaidMonthsRemaining);
        $this->assertSame('0.00', $prepaidResult->totalBill);
    }

    public function test_rejecting_sends_the_batch_back_to_draft_with_the_reason(): void
    {
        $batch = $this->submittedWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]);

        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/reject", [])->assertSessionHasErrors('rejection_reason');
        $this->post("/opening-balances/{$batch->uuid}/reject", ['rejection_reason' => 'Zone AR02 totals do not match the book.'])
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('draft', $batch->status);
        $this->assertSame('Zone AR02 totals do not match the book.', $batch->rejection_reason);
        $this->assertSame(0, Manuscript::query()->where('opening_balance_batch_id', $batch->id)->count());
    }

    public function test_approval_refuses_if_a_customer_gained_billing_history_meanwhile(): void
    {
        [$customer] = $this->newCustomers(1);
        $batch = $this->submittedWith([['customer_uuid' => $customer->uuid, 'arrears' => '2500']]);
        ManuscriptFactory::new()->forPeriod(now()->format('Y-m'))->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve")->assertSessionHasErrors('batch');

        $this->assertSame('pending_approval', $batch->refresh()->status);
    }

    public function test_an_approved_batch_can_be_reopened_until_a_bill_run_locks_it(): void
    {
        [$customer] = $this->newCustomers(1);
        $batch = $this->submittedWith([['customer_uuid' => $customer->uuid, 'arrears' => '2500']]);
        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve");

        $this->post("/opening-balances/{$batch->uuid}/reopen")->assertSessionHas('success');
        $this->assertSame('draft', $batch->refresh()->status);
        $this->assertSame(0, Manuscript::query()->where('customer_id', $customer->id)->count());

        // Re-approve, then a bill run for the go-live month locks it.
        $this->actingAsSeededUser('terence@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/submit");
        $this->actingAsSeededUser('kelvin@shalomtech.dev');
        $this->post("/opening-balances/{$batch->uuid}/approve");
        ManuscriptFactory::new()->forPeriod($this->goLive)->create(['customer_id' => $customer->id]);

        $this->post("/opening-balances/{$batch->uuid}/reopen")->assertSessionHasErrors('batch');
        $this->assertSame('approved', $batch->refresh()->status);
    }

    public function test_a_draft_can_be_discarded_but_not_once_submitted(): void
    {
        $batch = $this->draftWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]);

        $this->delete("/opening-balances/{$batch->uuid}")->assertSessionHas('success');
        $this->assertNull(OpeningBalanceBatch::query()->find($batch->id));

        $submitted = $this->submittedWith([['customer_uuid' => $this->newCustomers(1)->first()->uuid, 'arrears' => '2500']]);
        $this->delete("/opening-balances/{$submitted->uuid}")->assertForbidden();
    }

    public function test_the_go_live_month_must_be_near_today(): void
    {
        $this->actingAsSeededUser('terence@shalomtech.dev');

        $this->post('/opening-balances', ['go_live_period' => '2020-01'])->assertSessionHasErrors('go_live_period');
        $this->post('/opening-balances', ['go_live_period' => 'next month'])->assertSessionHasErrors('go_live_period');
    }
}
