<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Jobs\SendPushNotificationJob;
use App\Models\Branch;
use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 D12: a notification about one branch's bon, handover,
 * adjustment or complaint reaches that branch's staff and the unfenced owner
 * / HQ-level staff / investors — never another branch's manager. Company-wide
 * notices still reach everyone in their audience.
 */
class NotificationBranchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $buea;

    private Branch $limbe;

    private User $kelvin;

    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->notifications = app(NotificationService::class);
    }

    public function test_a_notification_takes_the_branch_of_what_it_is_about(): void
    {
        $voucher = $this->voucher($this->buea);

        $notification = $this->notifications->broadcastToRole('manager', 'voucher.requested', 'info', 'Voucher to approve', 'x', null, 'voucher', $voucher->uuid);

        $this->assertSame($this->buea->id, $notification->branch_id);
        $this->assertNull($this->notifications->broadcastToRole('manager', 'opening_balances.submitted', 'info', 'Company-wide', 'x')->branch_id);
    }

    public function test_a_fenced_manager_sees_their_branch_and_company_wide_notices_only(): void
    {
        $bueaNotice = $this->notifications->broadcastToRole('manager', 't', 'info', 'Buea bon', 'x', branchId: $this->buea->id);
        $limbeNotice = $this->notifications->broadcastToRole('manager', 't', 'info', 'Limbe bon', 'x', branchId: $this->limbe->id);
        $companyNotice = $this->notifications->broadcastToRole('manager', 't', 'info', 'Company notice', 'x');

        $feed = $this->feedFor('manager', $this->buea->id);
        $uuids = collect($feed['items'])->pluck('uuid');

        $this->assertTrue($uuids->contains($bueaNotice->uuid));
        $this->assertTrue($uuids->contains($companyNotice->uuid));
        $this->assertFalse($uuids->contains($limbeNotice->uuid));

        // The unfenced owner / HQ-level manager sees them all.
        $this->assertTrue(collect($this->feedFor('manager', null)['items'])->pluck('uuid')->contains($limbeNotice->uuid));
    }

    public function test_a_fenced_manager_cannot_open_another_branchs_notice(): void
    {
        $limbeNotice = $this->notifications->broadcastToRole('manager', 't', 'info', 'Limbe bon', 'x', branchId: $this->limbe->id);

        $this->as('manager', $this->buea->id);
        $this->post("/notifications/{$limbeNotice->uuid}/read")->assertForbidden();
    }

    public function test_push_reaches_only_the_notified_branchs_agents(): void
    {
        $bueaAgent = $this->agentWithToken('terence@shalomtech.dev', $this->buea, 'ExponentPushToken[buea]');
        $limbeAgent = $this->agentWithToken('patience@shalomtech.dev', $this->limbe, 'ExponentPushToken[limbe]');
        // Hold the push job back: it would call Expo with these fake tokens and invalidate them.
        Bus::fake([SendPushNotificationJob::class]);

        $notification = $this->notifications->broadcastToAll('complaint.escalated_level_2', 'emergency', 'Outage', 'x', branchId: $this->buea->id);
        $tokens = app(PushTokenRepositoryInterface::class)->tokensForAudience($notification)->pluck('user_id');

        $this->assertTrue($tokens->contains($bueaAgent->id));
        $this->assertFalse($tokens->contains($limbeAgent->id));
    }

    /** @return array{items: array<int, array<string, mixed>>, unread_count: int, emergency: array<int, array<string, mixed>>} */
    private function feedFor(string $role, ?int $branchId): array
    {
        $tenantUser = TenantUser::query()->where('user_id', $this->kelvin->id)->firstOrFail();
        $context = new TenantContext($tenantUser, $role, $branchId);
        app()->instance(TenantContext::class, $context);

        return $this->notifications->feedForUser($this->kelvin, $context, 50);
    }

    private function agentWithToken(string $email, Branch $branch, string $token): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'agent', 'is_investor' => false]);
        AgentFactory::new()->create(['user_id' => $user->id, 'zone_id' => ZoneFactory::new()->create(['branch_id' => $branch->id])->id]);
        DevicePushToken::query()->create(['user_id' => $user->id, 'device_id' => 'device-'.$branch->id, 'expo_push_token' => $token, 'platform' => 'android', 'is_valid' => true, 'registered_at' => now()]);

        return $user;
    }

    private function voucher(Branch $branch): Voucher
    {
        return Voucher::query()->create([
            'number' => 'BC-NOTIF-'.random_int(100000, 999999),
            'kind' => Voucher::KIND_EXPENSE,
            'payee_name' => 'Payee',
            'purpose' => 'Test',
            'expense_category_id' => ExpenseCategoryFactory::new()->create()->id,
            'amount' => 1000,
            'needed_on' => now()->toDateString(),
            'status' => 'pending',
            'approval_tier' => Voucher::TIER_STANDARD,
            'approval_mode' => 'any',
            'requested_by' => $this->kelvin->id,
            'branch_id' => $branch->id,
        ]);
    }

    private function as(string $role, ?int $branchId): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
