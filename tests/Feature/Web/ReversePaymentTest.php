<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerManuscriptRecalculationService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * A verified payment that was wrong is reversed, never edited: it becomes
 * rejected and its receipt is voided (PaymentVerificationService::reverse()),
 * then the right payment is recorded again.
 */
class ReversePaymentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    public function test_the_office_reverses_a_verified_payment_and_its_receipt_is_voided(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');
        $this->assertSame('issued', $payment->receipt?->status);

        $this->as('terence@shalomtech.dev', 'admin');
        $this->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $page) => $page->where('can_reverse', true));
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong amount entered'])->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame('rejected', $payment->verification_status);
        $this->assertSame('void', $payment->receipt->status);
        $this->assertStringContainsString('Wrong amount entered', (string) $payment->verification->notes);
        $this->assertSame(0, ArrearsAdjustment::query()->where('customer_id', $this->customer->id)->count(), 'No bill counted it yet.');
    }

    public function test_a_reason_is_required(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('verified', $payment->fresh()->verification_status);
    }

    public function test_a_payment_counted_in_an_open_month_is_recalculated_out_of_the_bill(): void
    {
        $period = now()->format('Y-m');
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');
        $this->billWith($payment, $period);
        $this->assertSame('0.00', $this->arrearsFor($period), 'The payment covered this month.');

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Customer never paid'])->assertSessionHasNoErrors();

        // Owed once — no adjustment on top of the recalculation.
        $this->assertSame('2500.00', $this->arrearsFor($period));
        $this->assertSame(0, ArrearsAdjustment::query()->where('customer_id', $this->customer->id)->count());
    }

    public function test_a_payment_counted_in_a_closed_month_is_added_to_this_month_for_approval(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');
        $closed = now()->subMonthNoOverflow()->format('Y-m');
        $payment->update(['processed_at' => now()->subMonthNoOverflow(), 'processed_period' => $closed]);

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Customer never paid'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'waiting for approval'));

        $adjustment = ArrearsAdjustment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame('increase', $adjustment->direction);
        $this->assertSame('2500.00', (string) $adjustment->amount);
        $this->assertSame(now()->format('Y-m'), $adjustment->target_period);
        $this->assertSame('pending', $adjustment->status);
    }

    public function test_it_waits_while_a_bill_run_is_in_review(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');
        CommandRun::query()->create(['command' => 'manuscript:calculate', 'period' => now()->addMonthNoOverflow()->format('Y-m'), 'ran_at' => now(), 'status' => 'pending_review']);

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong'])->assertSessionHasErrors('reason');
        $this->assertSame('verified', $payment->fresh()->verification_status);
    }

    public function test_a_reversal_is_final(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong amount'])->assertSessionHasNoErrors();
        $this->assertNotNull($payment->fresh()->reversed_at);

        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'approve'])->assertForbidden();
        $this->put("/payments/{$payment->uuid}", ['amount' => 3000, 'frequency' => 'monthly'])->assertSessionHasErrors('amount');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Again'])->assertForbidden();

        $payment->refresh();
        $this->assertSame('rejected', $payment->verification_status);
        $this->assertSame('void', $payment->receipt->status);
        $this->assertSame('Wrong amount', $payment->reversal_reason);
    }

    public function test_a_verified_payment_cannot_be_deleted(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->as('kelvin@shalomtech.dev', 'super');
        $this->delete("/payments/{$payment->uuid}")->assertSessionHasErrors('payment');
        $this->assertNotNull($payment->fresh());
    }

    public function test_handed_over_cash_cannot_be_reversed(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');
        $this->post('/cash/remittances', ['declared_cash' => '2500'])->assertSessionHasNoErrors();
        $this->assertTrue($payment->remittanceLine()->exists());

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong amount'])->assertSessionHasErrors('reason');
        $this->assertSame('verified', $payment->fresh()->verification_status);
    }

    public function test_the_recorder_cannot_reverse_their_own_payment_but_the_owner_can(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Oops'])->assertForbidden();
        $this->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $page) => $page->where('can_reverse', false));

        $this->as('kelvin@shalomtech.dev', 'super');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Oops'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $payment->fresh()->verification_status);
    }

    public function test_an_agent_cannot_reverse(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->as('terence@shalomtech.dev', 'agent');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong'])->assertForbidden();
    }

    public function test_a_pending_payment_is_rejected_not_reversed_and_a_verified_one_is_not_rejected(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'reject', 'notes' => 'Wrong'])->assertSessionHasErrors('action');
        $this->assertSame('verified', $payment->fresh()->verification_status);

        $payment->update(['verification_status' => 'pending']);
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Wrong'])->assertForbidden();
    }

    public function test_the_money_on_a_verified_payment_cannot_be_edited(): void
    {
        $payment = $this->verifiedPaymentRecordedBy('kelvin@shalomtech.dev');

        $this->put("/payments/{$payment->uuid}", ['amount' => 3000, 'frequency' => 'monthly'])
            ->assertSessionHasErrors(['amount' => 'This payment has a receipt. Reverse it and record it again.']);
        $this->put("/payments/{$payment->uuid}", ['amount' => 2500, 'frequency' => 'months', 'months' => 2])->assertSessionHasErrors('amount');
        $this->assertSame('2500.00', (string) $payment->fresh()->amount);
    }

    private function verifiedPaymentRecordedBy(string $email): Payment
    {
        $this->as($email, 'manager');
        $this->post('/payments', ['customer_uuid' => $this->customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame('verified', $payment->verification_status);

        return $payment->load('receipt');
    }

    /**
     * A published bill for $period that counted $payment, as a real
     * manuscript:calculate run leaves it.
     */
    private function billWith(Payment $payment, string $period): void
    {
        app(CustomerManuscriptRecalculationService::class)->recalculateOne($this->customer, $period, trigger: 'test');
        $payment->update(['processed_at' => now(), 'processed_period' => $period]);
    }

    private function arrearsFor(string $period): string
    {
        return (string) Manuscript::query()->where('customer_id', $this->customer->id)->where('period', $period)->value('total_arrears');
    }

    private function as(string $email, string $role): void
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }
}
