<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\ComplaintData;
use App\Models\Company;
use App\Models\ComplaintEscalation;
use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\ArrearsAdjustmentService;
use App\Services\ComplaintEscalationService;
use App\Services\ComplaintService;
use App\Services\PaymentReversalRequestService;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\ComplaintFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Manager alerts (docs/plans/mobile-manager-app.md, wave 2): who is told
 * about what (App\Services\ActionAlertService and its callers), the
 * technician's alert on assignment, escalation to the owner, the morning
 * summary and quiet hours. Seeded swecom members: kelvin (super), patience
 * (admin), terence (manager), divine and blessing (agents).
 */
class ManagerAlertsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private User $patience;

    private User $terence;

    private User $divine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        Company::query()->update(['voucher_approval_limit' => '50000', 'voucher_daily_limit' => null]);
        Cache::forget(Company::CACHE_KEY);
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false, 'branch_id' => null]);

        $this->kelvin = $this->withRole('kelvin@shalomtech.dev', 'super');
        $this->patience = $this->withRole('patience@shalomtech.dev', 'admin');
        $this->terence = $this->withRole('terence@shalomtech.dev', 'manager');
        $this->divine = $this->withRole('divine@shalomtech.dev', 'agent');
    }

    private function withRole(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function alertedUserIds(string $type, string $sourceUuid): array
    {
        return Notification::query()->where('type', $type)->where('source_uuid', $sourceUuid)->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function test_a_voucher_request_rings_each_person_who_can_approve_it_but_not_the_requester(): void
    {
        $this->actingAs($this->terence);
        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Kumba',
            'purpose' => 'Generator fuel',
            'category_uuid' => ExpenseCategoryFactory::new()->create(['name' => 'Fuel'])->uuid,
            'amount' => '25000',
        ])->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $alerted = $this->alertedUserIds('inbox.voucher', $voucher->uuid);
        $this->assertContains($this->kelvin->id, $alerted);
        $this->assertContains($this->patience->id, $alerted);
        $this->assertNotContains($this->terence->id, $alerted, 'Nobody is alerted about their own request.');
        $this->assertNotContains($this->divine->id, $alerted);
        $this->assertSame('urgent', Notification::query()->where('type', 'inbox.voucher')->value('severity'));
    }

    public function test_an_arrears_request_and_its_second_stage_reach_the_right_people(): void
    {
        $adjustment = app(ArrearsAdjustmentService::class)->create(
            ArrearsAdjustmentData::fromArray([
                'customer_uuid' => CustomerFactory::new()->active()->create()->uuid,
                'target_period' => now()->format('Y-m'),
                'direction' => 'decrease',
                'target' => 'arrears',
                'amount' => '5000.00',
                'reason_category' => 'billing_error',
                'reason_note' => 'Typo',
            ]),
            $this->divine->id,
        );

        $alerted = $this->alertedUserIds('inbox.arrears', $adjustment->uuid);
        $this->assertContains($this->terence->id, $alerted);
        $this->assertNotContains($this->divine->id, $alerted);

        // A big one, approved once by the manager: only owner/admin are asked for the second approval.
        $big = ArrearsAdjustmentFactory::new()->requestedBy($this->divine->id)->withAmount('90000.00')->create();
        Notification::query()->where('source_uuid', $big->uuid)->delete();
        $this->actingAs($this->terence);
        $result = app(ArrearsAdjustmentService::class)->approve($big, $this->terence);

        $this->assertSame('pending_second_approval', $result->status);
        $this->assertSame([$this->kelvin->id, $this->patience->id], array_values(array_intersect($this->alertedUserIds('inbox.arrears', $big->uuid), [$this->kelvin->id, $this->patience->id, $this->terence->id])));
    }

    public function test_a_reversal_request_goes_to_the_office_not_to_other_collectors(): void
    {
        $blessing = $this->withRole('blessing@shalomtech.dev', 'agent');
        $payment = PaymentFactory::new()->create(['recorded_by' => $this->divine->id]);

        $request = app(PaymentReversalRequestService::class)->request($payment, $this->divine, 'Wrong customer');

        $alerted = $this->alertedUserIds('inbox.reversal', $payment->uuid);
        $this->assertContains($this->terence->id, $alerted);
        $this->assertNotContains($blessing->id, $alerted);
        $this->assertNotContains($this->divine->id, $alerted);
        $this->assertNotNull($request);
    }

    public function test_a_new_unassigned_complaint_alerts_whoever_assigns_technicians(): void
    {
        $complaint = app(ComplaintService::class)->create(
            ComplaintData::fromArray(['category' => 'operational', 'title' => 'No signal in Fiango', 'description' => 'Since morning', 'urgent' => true]),
            $this->divine->id,
        );

        $this->assertContains($this->terence->id, $this->alertedUserIds('inbox.complaint', $complaint->uuid));
        $this->assertSame('urgent', Notification::query()->where('type', 'inbox.complaint')->where('source_uuid', $complaint->uuid)->value('severity'));
    }

    public function test_assigning_a_complaint_rings_the_technician_at_once_and_the_sweep_does_not_repeat_it(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->divine->id)->create(['assigned_to' => null]);

        app(ComplaintService::class)->assign($complaint, $this->terence->uuid);

        $assigned = Notification::query()->where('type', 'complaint.assigned')->where('recipient_user_id', $this->terence->id)->get();
        $this->assertCount(1, $assigned);
        $this->assertSame('urgent', $assigned->first()->severity);
        $this->assertTrue(ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 0)->exists());

        app(ComplaintEscalationService::class)->sweep($complaint->fresh(), now());
        $this->assertSame(1, Notification::query()->where('type', 'complaint.assigned')->where('recipient_user_id', $this->terence->id)->count());

        // Reassigned: the new technician is rung too.
        app(ComplaintService::class)->assign($complaint->fresh(), $this->patience->uuid);
        $this->assertSame(1, Notification::query()->where('type', 'complaint.assigned')->where('recipient_user_id', $this->patience->id)->where('severity', 'urgent')->count());
    }

    public function test_a_voucher_left_waiting_rings_the_owner_once(): void
    {
        $this->actingAs($this->terence);
        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Kumba',
            'purpose' => 'Generator fuel',
            'category_uuid' => ExpenseCategoryFactory::new()->create(['name' => 'Fuel'])->uuid,
            'amount' => '1000',
        ])->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $voucher->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('alerts:escalate', ['tenant' => 'swecom'])->assertSuccessful();
        $this->artisan('alerts:escalate', ['tenant' => 'swecom'])->assertSuccessful();

        $this->assertSame([$this->kelvin->id], $this->alertedUserIds('inbox.escalated', $voucher->uuid));
    }

    public function test_the_morning_summary_goes_only_to_people_with_something_waiting(): void
    {
        ComplaintFactory::new()->submittedBy($this->divine->id)->create(['assigned_to' => null]);

        $this->artisan('alerts:digest', ['tenant' => 'swecom'])->assertSuccessful();

        $digests = Notification::query()->where('type', 'inbox.digest')->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->terence->id, $digests);
        $this->assertNotContains($this->divine->id, $digests);
        $this->assertSame('warning', Notification::query()->where('type', 'inbox.digest')->value('severity'));
    }

    public function test_a_shared_phone_only_rings_for_whoever_is_signed_in(): void
    {
        $token = ['device_id' => 'phone-1', 'expo_push_token' => 'ExponentPushToken[shared]', 'platform' => 'android'];

        Sanctum::actingAs($this->terence);
        $this->postJson('/api/v1/devices/push-token', $token)->assertSuccessful();

        // Someone else signs in on the same phone without terence signing out.
        Sanctum::actingAs($this->patience);
        $this->postJson('/api/v1/devices/push-token', $token)->assertSuccessful();

        $this->assertSame([$this->patience->id], DevicePushToken::query()->where('expo_push_token', 'ExponentPushToken[shared]')->where('is_valid', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        // Signing out releases the phone.
        $plainToken = $this->patience->createToken('mobile')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)->postJson('/api/v1/auth/logout', ['device_id' => 'phone-1'])->assertOk();
        $this->assertFalse(DevicePushToken::query()->where('user_id', $this->patience->id)->where('device_id', 'phone-1')->value('is_valid'));
    }

    public function test_quiet_hours_are_set_from_the_app(): void
    {
        Sanctum::actingAs($this->terence);

        $this->putJson('/api/v1/auth/alerts', ['quiet_start' => '22:00'])->assertJsonValidationErrors('quiet_end');
        $this->putJson('/api/v1/auth/alerts', ['quiet_start' => '22:00', 'quiet_end' => '22:00'])->assertJsonValidationErrors('quiet_start');
        $this->putJson('/api/v1/auth/alerts', ['quiet_start' => '22:00', 'quiet_end' => '06:00'])->assertOk()->assertJsonPath('data.quiet_end', '06:00');

        $this->assertTrue($this->terence->fresh()->inQuietHours(now()->setTimezone('Africa/Lagos')->setTime(23, 0)));
        $this->assertFalse($this->terence->fresh()->inQuietHours(now()->setTimezone('Africa/Lagos')->setTime(12, 0)));

        $this->putJson('/api/v1/auth/alerts', ['quiet_start' => null, 'quiet_end' => null])->assertOk();
        $this->getJson('/api/v1/auth/alerts')->assertJsonPath('data.quiet_start', null);
    }
}
