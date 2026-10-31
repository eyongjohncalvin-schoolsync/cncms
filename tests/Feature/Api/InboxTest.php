<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\PaymentReversalRequest;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\PaymentReversalRequestService;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\ComplaintFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The phone's manager inbox (Api\InboxController, App\Services\
 * ManagerInboxService): what shows for whom, and that each decision goes
 * through the web's own rules. kelvin asks, terence decides, as in
 * Web\VoucherTest.
 */
class InboxTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private User $terence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();

        Company::query()->update(['voucher_approval_limit' => '50000', 'voucher_daily_limit' => null]);
        Cache::forget(Company::CACHE_KEY);
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false]);

        $this->kelvin = $this->withRole('kelvin@shalomtech.dev', 'agent');
        $this->terence = $this->withRole('terence@shalomtech.dev', 'manager');
    }

    private function withRole(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        return $user;
    }

    private function voucher(): Voucher
    {
        $this->actingAs($this->kelvin);
        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Kumba',
            'purpose' => 'Generator fuel',
            'category_uuid' => ExpenseCategoryFactory::new()->create(['name' => 'Fuel'])->uuid,
            'amount' => '25000',
        ])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function inboxKeysFor(User $user): array
    {
        Sanctum::actingAs($user);

        return array_column($this->getJson('/api/v1/inbox')->assertOk()->json('data'), 'key');
    }

    public function test_a_pending_voucher_waits_for_the_manager_not_the_person_who_asked(): void
    {
        $voucher = $this->voucher();

        $this->assertContains("voucher:{$voucher->uuid}", $this->inboxKeysFor($this->terence));
        $this->assertNotContains("voucher:{$voucher->uuid}", $this->inboxKeysFor($this->kelvin));

        Sanctum::actingAs($this->terence);
        $item = collect($this->getJson('/api/v1/inbox')->json('data'))->firstWhere('key', "voucher:{$voucher->uuid}");
        $this->assertSame(['approve', 'reject'], $item['actions']);
        $this->assertSame('now', $item['severity']);
        $this->assertSame('25000.00', $item['amount']);
    }

    public function test_the_manager_approves_or_rejects_a_voucher_from_the_phone(): void
    {
        $approved = $this->voucher();
        $rejected = $this->voucher();

        Sanctum::actingAs($this->terence);
        $this->postJson("/api/v1/inbox/vouchers/{$approved->uuid}/approve", ['note' => 'Keep the receipt'])->assertOk()->assertJsonPath('message', "{$approved->number} approved — it can now be paid.");
        $this->assertSame('approved', $approved->refresh()->status);

        $this->postJson("/api/v1/inbox/vouchers/{$rejected->uuid}/reject", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/inbox/vouchers/{$rejected->uuid}/reject", ['reason' => 'Use the old stock'])->assertOk();
        $this->assertSame('rejected', $rejected->refresh()->status);

        // Decided: gone from the inbox, and can't be decided twice.
        $this->assertNotContains("voucher:{$approved->uuid}", $this->inboxKeysFor($this->terence));
        $this->postJson("/api/v1/inbox/vouchers/{$approved->uuid}/approve")->assertForbidden();
    }

    public function test_nobody_approves_their_own_voucher_from_the_phone(): void
    {
        $voucher = $this->voucher();

        Sanctum::actingAs($this->kelvin);
        $this->postJson("/api/v1/inbox/vouchers/{$voucher->uuid}/approve")->assertForbidden();
        $this->assertSame('pending', $voucher->refresh()->status);
    }

    public function test_arrears_adjustments_follow_maker_checker(): void
    {
        $adjustment = ArrearsAdjustmentFactory::new()->requestedBy($this->kelvin->id)->create();

        $this->assertContains("arrears_adjustment:{$adjustment->uuid}", $this->inboxKeysFor($this->terence));

        $this->withRole('kelvin@shalomtech.dev', 'manager');
        Sanctum::actingAs($this->kelvin);
        $this->postJson("/api/v1/inbox/arrears-adjustments/{$adjustment->uuid}/approve")->assertForbidden();

        Sanctum::actingAs($this->terence);
        $this->postJson("/api/v1/inbox/arrears-adjustments/{$adjustment->uuid}/approve")->assertOk();
        $this->assertNotSame('pending', $adjustment->refresh()->status);

        $other = ArrearsAdjustmentFactory::new()->requestedBy($this->kelvin->id)->create();
        $this->postJson("/api/v1/inbox/arrears-adjustments/{$other->uuid}/reject", ['rejection_reason' => 'No proof'])->assertOk();
        $this->assertSame('rejected', $other->refresh()->status);
    }

    public function test_a_collectors_reversal_request_can_be_declined_with_a_note(): void
    {
        $payment = PaymentFactory::new()->create(['recorded_by' => $this->kelvin->id]);
        $request = app(PaymentReversalRequestService::class)->request($payment, $this->kelvin, 'Typed the wrong customer');

        Sanctum::actingAs($this->terence);
        $item = collect($this->getJson('/api/v1/inbox')->json('data'))->firstWhere('key', "reversal_request:{$request->uuid}");
        $this->assertNotNull($item);
        $this->assertContains('decline', $item['actions']);

        $this->postJson("/api/v1/inbox/reversal-requests/{$request->uuid}/decline", ['note' => 'Customer confirmed it'])->assertOk();
        $this->assertSame(PaymentReversalRequest::STATUS_DECLINED, $request->refresh()->status);
    }

    public function test_a_reversal_request_can_be_carried_out_from_the_phone(): void
    {
        $payment = PaymentFactory::new()->create(['recorded_by' => $this->kelvin->id]);
        $request = app(PaymentReversalRequestService::class)->request($payment, $this->kelvin, 'Typed the wrong customer');

        Sanctum::actingAs($this->terence);
        $this->postJson("/api/v1/inbox/reversal-requests/{$request->uuid}/reverse", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/inbox/reversal-requests/{$request->uuid}/reverse", ['reason' => 'Wrong customer', 'money' => 'none'])->assertOk();

        $this->assertTrue($payment->refresh()->isReversed());
        $this->assertSame(PaymentReversalRequest::STATUS_DONE, $request->refresh()->status);
        $this->postJson("/api/v1/inbox/reversal-requests/{$request->uuid}/reverse", ['reason' => 'Again'])->assertJsonValidationErrors('reason');
    }

    public function test_unassigned_complaints_wait_for_whoever_assigns_technicians(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->kelvin->id)->create(['assigned_to' => null]);

        $this->assertContains("complaint:{$complaint->uuid}", $this->inboxKeysFor($this->terence));
        $this->assertNotContains("complaint:{$complaint->uuid}", $this->inboxKeysFor($this->kelvin));

        Sanctum::actingAs($this->terence);
        $this->getJson("/api/v1/inbox/complaints/{$complaint->uuid}/technicians")->assertOk()->assertJsonStructure(['data']);

        Sanctum::actingAs($this->kelvin);
        $this->getJson("/api/v1/inbox/complaints/{$complaint->uuid}/technicians")->assertForbidden();
    }

    public function test_an_escalated_complaint_is_urgent_even_when_assigned(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->kelvin->id)->escalated()->create(['assigned_to' => $this->kelvin->id]);

        Sanctum::actingAs($this->terence);
        $item = collect($this->getJson('/api/v1/inbox')->json('data'))->firstWhere('key', "complaint:{$complaint->uuid}");

        $this->assertSame('now', $item['severity']);
        $this->assertStringStartsWith('Escalated: ', $item['title']);
    }

    public function test_web_only_work_shows_as_a_count_with_a_link(): void
    {
        PaymentFactory::new()->pending()->create(['recorded_by' => $this->kelvin->id]);
        Cache::flush();

        Sanctum::actingAs($this->terence);
        $item = collect($this->getJson('/api/v1/inbox')->json('data'))->firstWhere('type', 'payments_to_verify');

        $this->assertNotNull($item);
        $this->assertSame([], $item['actions']);
        $this->assertSame('/payments?verification_status=pending', $item['web_path']);
    }

    public function test_the_inbox_needs_a_signed_in_member(): void
    {
        $this->getJson('/api/v1/inbox')->assertUnauthorized();
    }
}
