<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Jobs\ComputeManuscriptChunkJob;
use App\Models\CommandRun;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\FieldInstallation;
use App\Models\FreeMonthGrant;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Zone;
use App\Services\BillNotificationService;
use App\Services\CustomerEligibilityService;
use App\Services\FreeMonthGrantService;
use App\Services\ManuscriptCalculator;
use App\Services\RemittanceService;
use Database\Factories\CustomerFactory;
use Database\Factories\InstallationOfferFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\RegistersFieldInstallations;
use Tests\TestCase;

/**
 * Offline field installations, the office's side
 * (docs/plans/installation-and-promotions.md, phase 3): a pending customer
 * is never billed; the office activates it (confirming or correcting zone,
 * free months and connection date — including a month already generated)
 * or rejects it (payments rejected, receipts voided, refund bon, archived).
 *
 * The seeded owner (kelvin) is the field agent; the seeded admin
 * (patience) is the office.
 */
class FieldInstallationActivationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use RegistersFieldInstallations;

    private Zone $zone;

    private Service $tv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->zone = ZoneFactory::new()->create();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->tv->update(['price' => 2500]);

        Company::query()->update(['cash_controls_start_date' => '2026-09-01']);
        Cache::forget(Company::CACHE_KEY);
        Company::flushMemo();
    }

    private function office(string $role = 'admin'): User
    {
        $user = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * An agent's registration, synced.
     *
     * @return array{customer: Customer, field: FieldInstallation, item: array<string, mixed>}
     */
    private function registered(array $extra = [], int $paidMonths = 2, string $paid = '10000'): array
    {
        $phone = $this->fieldAgentPhone($this->zone);
        $item = $this->registration($phone, $this->zone, $this->tv->uuid, $extra, $paidMonths, $paid);
        $this->assertSame('synced', $this->pushInstallations($phone, [$item])['results']['installations'][0]['status']);

        return [
            'customer' => $this->registeredCustomer($item),
            'field' => FieldInstallation::query()->where('local_uuid', $item['local_uuid'])->sole(),
            'item' => $item,
        ];
    }

    private function row(Customer $customer, string $period): ?Manuscript
    {
        return Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->first();
    }

    public function test_a_pending_customer_is_never_billed(): void
    {
        ['customer' => $pending] = $this->registered();
        $active = CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'bill' => 2500, 'status' => 'active']);

        // The engine reads it as not started.
        $result = app(ManuscriptCalculator::class)->calculate($pending->fresh(), '2026-11', null, new Collection, new Collection);
        $this->assertTrue($result->isFrozen);
        $this->assertSame('not_started', $result->frozenReason);
        $this->assertSame('0.00', $result->totalBill);

        // The run gives it no register row.
        $run = CommandRun::query()->create(['command' => 'manuscript:calculate', 'period' => '2026-11', 'ran_at' => now(), 'status' => 'queued']);
        app()->call([new ComputeManuscriptChunkJob($run->id, '2026-11', [$pending->id, $active->id], 0), 'handle']);
        $customers = $run->fresh()->computed_result['chunk_0']['customers'];
        $this->assertArrayHasKey((string) $active->id, $customers);
        $this->assertArrayNotHasKey((string) $pending->id, $customers);

        // A grant approval or a payment can't write rows for it either.
        app(FreeMonthGrantService::class)->recalculateFrom($pending->fresh(), '2026-10');
        $this->assertSame(0, Manuscript::query()->where('customer_id', $pending->id)->count());

        // No reminder, no disconnection eligibility, "pending" on its page.
        $this->assertNull(app(BillNotificationService::class)->composeMessage($pending->fresh()));
        $this->travelTo(Carbon::parse('2026-10-25 09:00', 'Africa/Lagos'));
        $this->assertFalse(app(CustomerEligibilityService::class)->eligibleForDisconnection($this->zone->id)->contains('uuid', $pending->uuid));

        $this->office();
        $this->get("/customers/{$pending->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customer.activation_status', 'pending')
            ->where('customer.coverage.label', 'Pending activation — not billed until the office activates')
            ->where('customer.field_installation.status', 'pending')
            ->where('customer.field_installation.can_decide', true));
    }

    public function test_the_activation_queue_and_the_dashboard_count(): void
    {
        ['customer' => $pending] = $this->registered();
        $this->office();

        $this->get('/customers?pending_activation=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pending_activation_view', true)
            ->where('customers.data.0.uuid', $pending->uuid)
            ->where('customers.meta.total', 1));

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.pending_activations', 1));
    }

    public function test_activation_starts_billing_with_the_proposed_months(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered();
        $office = $this->office();

        $this->post("/field-installations/{$field->uuid}/activate", [])->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertFalse($customer->isPendingActivation());
        $this->assertSame('2026-10', $customer->billing_starts_period);

        // Installation fully paid in the field: the installation month free at once (D17).
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
        $this->assertSame('2026-10', $grant->start_period);

        $field->refresh();
        $this->assertSame(FieldInstallation::STATUS_ACTIVATED, $field->status);
        $this->assertSame($office->id, $field->decided_by);
        $this->assertNull($field->corrections);

        // Oct free, Nov–Dec paid upfront, billed from Jan 2027.
        $this->assertSame('2027-01', $this->get("/customers/{$customer->uuid}")->viewData('page')['props']['customer']['coverage']['first_bill']);
    }

    public function test_activation_after_the_month_was_generated_writes_its_rows(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered(paidMonths: 0);

        // The October and November runs are already out (Option X).
        $this->travelTo(Carbon::parse('2026-10-28 09:00', 'Africa/Lagos'));
        foreach (['2026-10', '2026-11'] as $period) {
            CommandRun::query()->create(['command' => 'manuscript:calculate', 'period' => $period, 'ran_at' => now(), 'status' => 'published', 'published_at' => now()]);
        }
        $this->assertNull($this->row($customer, '2026-10'));

        $this->office();
        $this->post("/field-installations/{$field->uuid}/activate", [])->assertSessionHasNoErrors();

        $this->assertSame('0.00', (string) $this->row($customer, '2026-10')->total_bill, 'installation month free');
        $this->assertSame('2500.00', (string) $this->row($customer, '2026-11')->bill, 'November billed — not skipped');
    }

    public function test_the_office_corrects_zone_free_months_and_connection_date(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered(paidMonths: 0);
        $otherZone = ZoneFactory::new()->create();
        $offer = InstallationOfferFactory::new()->create(['fee' => 10000]);
        $offer->serviceMonths()->create(['service_id' => $this->tv->id, 'free_months' => 2]);
        $this->office();

        $this->post("/field-installations/{$field->uuid}/activate", [
            'zone_uuid' => $otherZone->uuid,
            'installation_offer_uuid' => $offer->uuid,
            'connected_on' => '2026-10-05',
            'service_plans' => [['service_uuid' => $this->tv->uuid, 'free_from' => '2026-10', 'free_months' => 2]],
            'note' => 'Checked with the customer',
        ])->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame($otherZone->id, $customer->zone_id);
        $this->assertSame('2026-10-05', $customer->connected_on->toDateString());
        $this->assertSame(2, (int) FreeMonthGrant::query()->where('customer_id', $customer->id)->sole()->months);

        $field->refresh();
        $this->assertEqualsCanonicalizing(['zone', 'installation_offer_uuid', 'connected_on', 'free_months'], array_keys($field->corrections));
        $this->assertSame('Checked with the customer', $field->decision_note);
    }

    public function test_free_months_without_the_installation_fully_paid_wait_for_approval(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered(paidMonths: 0, paid: '4000');
        $this->office('manager');

        $this->post("/field-installations/{$field->uuid}/activate", [])->assertSessionHasErrors('installation_reason');
        $this->post("/field-installations/{$field->uuid}/activate", ['installation_reason' => 'Promised by the agent'])->assertSessionHasNoErrors();

        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, FreeMonthGrant::query()->where('customer_id', $customer->id)->sole()->status);
        $this->assertSame('2026-10', $customer->fresh()->billing_starts_period);
    }

    public function test_rejection_voids_the_money_raises_a_refund_bon_and_archives(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered();
        $office = $this->office();

        $this->post("/field-installations/{$field->uuid}/reject", [])->assertSessionHasErrors('reason');
        $this->post("/field-installations/{$field->uuid}/reject", ['reason' => 'Customer already has an account'])
            ->assertRedirect('/customers?pending_activation=1');

        $payments = Payment::query()->with('receipt')->where('field_installation_id', $field->id)->get();
        $this->assertCount(2, $payments);
        $this->assertTrue($payments->every(fn (Payment $p): bool => $p->verification_status === 'rejected'));
        $this->assertTrue($payments->every(fn (Payment $p): bool => $p->receipt->status === PaymentReceipt::STATUS_VOID));

        $field->refresh();
        $this->assertSame(FieldInstallation::STATUS_REJECTED, $field->status);
        $this->assertSame('Customer already has an account', $field->decision_note);
        $this->assertSame($office->id, $field->decided_by);

        $bon = Voucher::query()->findOrFail($field->refund_voucher_id);
        $this->assertSame('15000.00', (string) $bon->amount);
        $this->assertTrue($bon->isPending(), 'the refund bon waits for approval like any bon');

        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(CustomerCharge::STATUS_REFUNDED, $charge->status);
        $this->assertSame($bon->id, $charge->refund_voucher_id);

        $customer = Customer::query()->withTrashed()->findOrFail($customer->id);
        $this->assertTrue($customer->trashed());
        $this->assertFalse($customer->isPendingActivation());

        // The cash still comes in through the agent's remittance.
        $agent = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $outstanding = app(RemittanceService::class)->outstandingQuery($agent)->pluck('id');
        $this->assertTrue($payments->pluck('id')->every(fn (int $id): bool => $outstanding->contains($id)));

        // The phone learns why on its next pull (as the agent, not the
        // office's web session).
        $this->app['auth']->forgetGuards();
        $pull = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('agent'))->getJson('/api/v1/sync/pull')->assertOk();
        $this->assertSame('rejected', $pull->json('changes.installations.0.status'));
        $this->assertSame('Customer already has an account', $pull->json('changes.installations.0.reason'));
        $this->assertContains($customer->uuid, $pull->json('changes.customers.deleted'));
    }

    public function test_only_the_office_decides_and_only_once(): void
    {
        ['field' => $field] = $this->registered();

        $this->office('agent');
        $this->post("/field-installations/{$field->uuid}/activate", [])->assertForbidden();
        $this->post("/field-installations/{$field->uuid}/reject", ['reason' => 'No'])->assertForbidden();

        $this->office();
        $this->post("/field-installations/{$field->uuid}/activate", [])->assertSessionHasNoErrors();
        $this->post("/field-installations/{$field->uuid}/reject", ['reason' => 'Too late'])->assertForbidden();
    }

    public function test_an_impersonating_landlord_cannot_activate_or_reject(): void
    {
        ['customer' => $customer, 'field' => $field] = $this->registered();

        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();
        $patience = $this->office();
        $patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();

        $this->actingAs($landlord)->post("/landlord/impersonate/{$patience->uuid}")->assertRedirect('/dashboard');

        $this->post("/field-installations/{$field->uuid}/activate", [])->assertSessionHas('error');
        $this->post("/field-installations/{$field->uuid}/reject", ['reason' => 'Nope'])->assertSessionHas('error');

        $this->assertTrue($customer->fresh()->isPendingActivation());
        $this->assertSame(FieldInstallation::STATUS_PENDING, $field->fresh()->status);
    }
}
