<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashAlertService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * A second subscription payment for the same customer in the same month
 * (App\Services\PaymentService::flagPossibleDuplicate()): the web asks the
 * collector why it is fine; offline sync records it anyway (the cash was
 * collected) as `unconfirmed`. Either way it is flagged, never verified on
 * the spot, and only someone other than its recorder may approve it.
 */
class DuplicatePaymentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    public function test_a_second_payment_this_month_asks_why_it_is_fine(): void
    {
        $this->as('manager');
        $this->pay()->assertSessionHasNoErrors();

        $this->pay()->assertSessionHasErrors('duplicate');
        $this->assertSame(1, $this->payments()->count());
        $this->assertStringContainsString('already paid 2,500 FCFA', (string) session('errors')->first('duplicate'));
    }

    public function test_a_confirmed_second_payment_is_recorded_flagged_and_left_pending(): void
    {
        $this->as('manager');
        $this->pay();
        $first = $this->payments()->sole();

        $this->pay(['duplicate_reason' => 'advance'])->assertSessionHasNoErrors();

        $second = $this->payments()->whereKeyNot($first->id)->sole();
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->assertSame('advance', $second->duplicate_reason);
        $this->assertSame('pending', $second->verification_status, 'Never verified on the spot, even by the office.');
    }

    public function test_paying_ahead_is_verified_on_the_spot_only_for_a_real_prepayment(): void
    {
        $this->as('manager');
        $this->pay();

        // A real 2-month prepayment: borne out, verified like any payment.
        $this->pay(['duplicate_reason' => 'advance', 'amount' => 5000, 'frequency' => 'months', 'months' => 2])->assertSessionHasNoErrors();
        $this->assertSame('verified', $this->payments()->latest('id')->first()->verification_status);

        // "Advance" on a plain monthly payment proves nothing: the office checks it.
        $this->pay(['duplicate_reason' => 'advance', 'amount' => 3000])->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->payments()->latest('id')->first()->verification_status);
    }

    public function test_the_same_amount_within_three_days_always_goes_to_the_office(): void
    {
        $this->as('manager');
        $this->pay(['amount' => 5000, 'frequency' => 'months', 'months' => 2]);

        $this->pay(['duplicate_reason' => 'advance', 'amount' => 5000, 'frequency' => 'months', 'months' => 2])->assertSessionHasNoErrors();

        $this->assertSame('pending', $this->payments()->latest('id')->first()->verification_status);
    }

    public function test_paying_old_debt_is_verified_on_the_spot_only_when_the_debt_is_real(): void
    {
        ManuscriptFactory::new()->withArrears(4000)->create(['customer_id' => $this->customer->id]);
        $this->as('manager');
        $this->pay();

        $this->pay(['duplicate_reason' => 'arrears', 'amount' => 4000])->assertSessionHasNoErrors();
        $this->assertSame('verified', $this->payments()->latest('id')->first()->verification_status, '4,000 of debt owed.');

        // More than the debt plus this month's bill: the office checks it.
        $this->pay(['duplicate_reason' => 'arrears', 'amount' => 9000])->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->payments()->latest('id')->first()->verification_status);
    }

    public function test_the_same_amount_either_side_of_a_month_end_is_caught(): void
    {
        $this->as('manager');
        $this->travelTo(Carbon::parse('2026-10-31 18:00', 'Africa/Lagos'));
        $this->pay()->assertSessionHasNoErrors();

        // 1 November: a new month, but the same 2,500 a day later — the classic double entry.
        $this->travelTo(Carbon::parse('2026-11-01 09:00', 'Africa/Lagos'));
        $this->pay()->assertSessionHasErrors('duplicate');

        // A different amount in the new month is just this month's payment.
        $this->pay(['amount' => 3000])->assertSessionHasNoErrors();
        $this->assertNull($this->payments()->latest('id')->first()->duplicate_of_id);
    }

    public function test_old_debt_already_paid_off_proves_nothing(): void
    {
        ManuscriptFactory::new()->withArrears(2500)->create(['customer_id' => $this->customer->id]);
        $this->as('manager');
        $this->pay(['amount' => 5000]); // clears the debt and this month

        $this->pay(['duplicate_reason' => 'arrears', 'amount' => 2000])->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->payments()->latest('id')->first()->verification_status, 'No debt left to pay: the office checks it.');
    }

    public function test_other_needs_a_note(): void
    {
        $this->as('manager');
        $this->pay();

        $this->pay(['duplicate_reason' => 'other'])->assertSessionHasErrors('duplicate_note');
        $this->pay(['duplicate_reason' => 'other', 'duplicate_note' => 'Paid for his brother'])->assertSessionHasNoErrors();
    }

    public function test_last_months_and_rejected_payments_do_not_count(): void
    {
        PaymentFactory::new()->create(['customer_id' => $this->customer->id, 'amount' => 2500, 'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);
        PaymentFactory::new()->create(['customer_id' => $this->customer->id, 'amount' => 2500, 'verification_status' => 'rejected']);
        $this->as('manager');

        $this->pay()->assertSessionHasNoErrors();
        $this->assertNull($this->payments()->latest('id')->first()->duplicate_of_id);
    }

    public function test_an_offline_phone_s_second_payment_is_kept_but_flagged_unconfirmed(): void
    {
        $this->as('manager');
        $this->pay();
        $token = $this->tokenForRole('manager');

        $results = $this->withToken($token)->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-1',
            'changes' => ['payments' => [
                ['local_uuid' => $unconfirmed = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'],
                ['local_uuid' => $confirmed = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 5000, 'frequency' => 'months', 'months' => 2, 'duplicate_reason' => 'advance'],
            ]],
        ])->assertOk()->json('results.payments');

        $this->assertSame(['synced', 'synced'], collect($results)->pluck('status')->all(), 'Cash already collected is never refused.');
        $this->assertSame('unconfirmed', Payment::query()->where('local_uuid', $unconfirmed)->value('duplicate_reason'));
        $this->assertSame('advance', Payment::query()->where('local_uuid', $confirmed)->value('duplicate_reason'));
        $this->assertSame('pending', Payment::query()->where('local_uuid', $unconfirmed)->value('verification_status'), 'Unconfirmed goes to the office.');
        $this->assertSame('verified', Payment::query()->where('local_uuid', $confirmed)->value('verification_status'), 'A real 2-month prepayment bears "paying ahead" out.');
    }

    public function test_two_payments_in_one_sync_flag_the_second_against_the_first(): void
    {
        $token = $this->tokenForRole('manager');

        $this->withToken($token)->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-2',
            'changes' => ['payments' => [
                ['local_uuid' => $first = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'],
                ['local_uuid' => $second = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'],
            ]],
        ])->assertOk();

        $this->assertNull(Payment::query()->where('local_uuid', $first)->value('duplicate_of_id'));
        $this->assertSame(
            Payment::query()->where('local_uuid', $first)->value('id'),
            Payment::query()->where('local_uuid', $second)->value('duplicate_of_id'),
        );
    }

    public function test_only_someone_other_than_the_recorder_approves_it(): void
    {
        $this->as('manager');
        $this->pay();
        $this->pay(['duplicate_reason' => 'arrears']);
        $flagged = $this->payments()->whereNotNull('duplicate_of_id')->sole();

        // Its recorder can't…
        $this->post("/payments/{$flagged->uuid}/verify", ['action' => 'approve'])->assertForbidden();

        // …nor can it go through bulk verification…
        $this->as('admin');
        $this->post('/payments/bulk-verify', ['payment_uuids' => [$flagged->uuid]]);
        $this->assertSame('pending', $flagged->fresh()->verification_status);

        // …but another office user can, after opening it.
        $other = TenantUser::query()->where('user_id', User::query()->where('email', 'terence@shalomtech.dev')->value('id'))->firstOrFail();
        $other->update(['role' => 'manager', 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs(User::query()->findOrFail($other->user_id));

        $this->post("/payments/{$flagged->uuid}/verify", ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $flagged->fresh()->verification_status);
    }

    public function test_the_owner_may_approve_their_own(): void
    {
        $this->as('super');
        $this->pay();
        $this->pay(['duplicate_reason' => 'advance']);
        $flagged = $this->payments()->whereNotNull('duplicate_of_id')->sole();

        $this->post("/payments/{$flagged->uuid}/verify", ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $flagged->fresh()->verification_status);
    }

    public function test_the_office_sees_it_on_the_payment_and_in_cash_alerts(): void
    {
        $this->as('manager');
        $this->pay();
        $this->pay(['duplicate_reason' => 'refund']);
        $flagged = $this->payments()->whereNotNull('duplicate_of_id')->sole();

        $this->as('admin');
        $this->get("/payments/{$flagged->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payment.possible_duplicate', true)
            ->where('payment.duplicate_reason', Payment::DUPLICATE_REASONS['refund'])
            ->where('payment.duplicate_of.amount', '2500.00'));

        $this->assertSame(1, app(CashAlertService::class)->counts()['possible_duplicate_payments'] ?? 0);
    }

    public function test_the_payments_page_has_a_possible_duplicates_tab(): void
    {
        $this->as('manager');
        $this->pay();
        $this->pay(['duplicate_reason' => 'refund']);
        $flagged = $this->payments()->whereNotNull('duplicate_of_id')->sole();

        $this->as('admin');
        $this->get('/payments?duplicates=1&per_page=500')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.duplicates', true)
            // Both of the month's payments, side by side — not only the flagged one.
            ->where('payments.data', fn ($rows) => collect($rows)->where('customer_name', $this->customer->name)->pluck('uuid')->sort()->values()->all()
                === $this->payments()->pluck('uuid')->sort()->values()->all() && collect($rows)->contains('uuid', $flagged->uuid))
            ->where('statusCounts.duplicates', fn ($count) => $count >= 2));
    }

    /**
     * Double payments from before the check existed (owner, 2026-10-27):
     * never flagged, verified, months ago — the tab still finds them.
     */
    public function test_the_tab_finds_old_unflagged_double_payments_of_a_month(): void
    {
        $double = $this->oldPayments([['2025-11-26 09:00', 2500], ['2025-11-26 09:05', 2500]]);
        $twoMonths = $this->oldPayments([['2025-09-10 09:00', 2500], ['2025-10-10 09:00', 2500]], CustomerFactory::new()->create(['zone_id' => $this->customer->zone_id, 'bill' => 2500, 'status' => 'active']));
        $rejected = $this->oldPayments([['2025-08-03 09:00', 2500]]);
        $rejected[0]->update(['verification_status' => 'rejected']);

        $this->as('admin');
        $this->get('/payments?duplicates=1&per_page=500')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'all')
            ->where('payments.data', function ($rows) use ($double, $twoMonths, $rejected): bool {
                $uuids = collect($rows)->pluck('uuid');

                return $uuids->contains($double[0]->uuid) && $uuids->contains($double[1]->uuid)
                    && ! $uuids->contains($twoMonths[0]->uuid) && ! $uuids->contains($twoMonths[1]->uuid)
                    && ! $uuids->contains($rejected[0]->uuid);
            }));

        // A month picked narrows it to that month.
        $this->get('/payments?duplicates=1&per_page=500&from=2025-11-01&to=2025-11-30')->assertInertia(fn (Assert $page) => $page
            ->where('payments.data', fn ($rows) => collect($rows)->pluck('uuid')->contains($double[1]->uuid)));
    }

    public function test_not_a_duplicate_clears_the_customers_month_from_the_tab(): void
    {
        $double = $this->oldPayments([['2025-11-26 09:00', 2500], ['2025-11-26 09:05', 2500]]);
        $other = $this->oldPayments([['2025-07-26 09:00', 2500], ['2025-07-26 09:05', 2500]]);

        $this->as('admin');
        $this->post("/payments/{$double[1]->uuid}/not-duplicate")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($double[0]->fresh()->duplicate_cleared_at, 'The whole month is cleared, not just one payment.');
        $this->assertSame($this->kelvin->id, $double[1]->fresh()->duplicate_cleared_by);
        $this->assertNull($other[0]->fresh()->duplicate_cleared_at, 'Another month stays.');

        $this->get('/payments?duplicates=1&per_page=500')->assertInertia(fn (Assert $page) => $page
            ->where('payments.data', function ($rows) use ($double, $other): bool {
                $uuids = collect($rows)->pluck('uuid');

                return ! $uuids->contains($double[0]->uuid) && ! $uuids->contains($double[1]->uuid) && $uuids->contains($other[0]->uuid);
            }));
    }

    public function test_only_the_office_and_not_the_recorder_clears_a_duplicate(): void
    {
        $double = $this->oldPayments([['2025-11-26 09:00', 2500], ['2025-11-26 09:05', 2500]]);

        $this->as('agent');
        $this->post("/payments/{$double[1]->uuid}/not-duplicate")->assertForbidden();

        $double[1]->update(['recorded_by' => $this->kelvin->id]);
        $this->as('admin');
        $this->post("/payments/{$double[1]->uuid}/not-duplicate")->assertForbidden();
        $this->assertNull($double[0]->fresh()->duplicate_cleared_at);
    }

    /**
     * Two payments for one customer at the same moment: recording one holds
     * that customer's lock until it commits, so the other waits and then
     * sees it. The test's open tenant transaction stands in for "not yet
     * committed"; a second database session plays the other collector.
     */
    public function test_recording_a_payment_holds_the_customers_lock_until_it_commits(): void
    {
        $this->as('manager');
        $this->pay()->assertSessionHasNoErrors();

        $other = DB::connection('pgsql');
        $this->assertFalse((bool) $other->scalar('select pg_try_advisory_xact_lock(7301, ?)', [$this->customer->id]), 'Another session must wait for this customer.');

        $someoneElse = CustomerFactory::new()->create(['zone_id' => $this->customer->zone_id, 'bill' => 2500, 'status' => 'active']);
        $this->assertTrue((bool) $other->scalar('select pg_try_advisory_xact_lock(7301, ?)', [$someoneElse->id]), 'Other customers never wait.');
    }

    /**
     * Verified, unflagged payments as the old system left them.
     *
     * @param  list<array{0: string, 1: int}>  $paid
     * @return list<Payment>
     */
    private function oldPayments(array $paid, ?Customer $customer = null): array
    {
        return array_map(fn (array $one): Payment => PaymentFactory::new()->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'amount' => $one[1],
            'collected_at' => Carbon::parse($one[0], 'Africa/Lagos'),
            'created_at' => Carbon::parse($one[0], 'Africa/Lagos'),
        ]), $paid);
    }

    private function pay(array $extra = []): TestResponse
    {
        return $this->post('/payments', [
            'customer_uuid' => $this->customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
            ...$extra,
        ]);
    }

    private function payments(): Builder
    {
        return Payment::query()->where('customer_id', $this->customer->id);
    }

    private function as(string $role): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
