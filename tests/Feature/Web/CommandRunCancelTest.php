<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * POST /settings/command-runs/{run}/cancel —
 * App\Http\Controllers\SettingsCommandRunController::cancel() (2026-08-27
 * security-review finding: no way existed anywhere to clear a `command_runs`
 * row permanently stuck at status='queued'). Covers authorization and the
 * only-works-on-queued restriction; see
 * tests/Feature/CommandRunCancelUnblocksDispatchTest.php for the
 * reproduce-the-lock-then-confirm-it's-freed scenario, which needs real
 * queue-safe fixtures rather than this class's DatabaseTransactions.
 */
class CommandRunCancelTest extends TestCase
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

    private function queuedRun(string $period): CommandRun
    {
        return CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => 'swecom', 'trigger' => 'cli'],
            'status' => 'queued',
        ]);
    }

    public function test_an_admin_can_cancel_a_stuck_queued_run(): void
    {
        $this->actingAsRole('admin');
        $run = $this->queuedRun('2032-01');

        $response = $this->post("/settings/command-runs/{$run->uuid}/cancel");
        $response->assertRedirect();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->metadata['cancelled_by'] ?? null);
        $this->assertNotNull($run->metadata['cancelled_at'] ?? null);
    }

    public function test_a_manager_cannot_cancel_a_run(): void
    {
        $this->actingAsRole('manager');
        $run = $this->queuedRun('2032-02');

        $this->post("/settings/command-runs/{$run->uuid}/cancel")->assertForbidden();

        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_an_agent_cannot_cancel_a_run(): void
    {
        $this->actingAsRole('agent');
        $run = $this->queuedRun('2032-03');

        $this->post("/settings/command-runs/{$run->uuid}/cancel")->assertForbidden();

        $this->assertSame('queued', $run->fresh()->status);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function nonQueuedStatuses(): array
    {
        return [
            ['pending_review'],
            ['published'],
            ['failed'],
        ];
    }

    #[DataProvider('nonQueuedStatuses')]
    public function test_cancel_is_refused_for_any_non_queued_status(string $status): void
    {
        $this->actingAsRole('admin');

        $run = CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => '2032-04',
            'ran_at' => now(),
            'metadata' => [],
            'status' => $status,
        ]);

        $response = $this->post("/settings/command-runs/{$run->uuid}/cancel");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame($status, $run->fresh()->status, "a run at status '{$status}' must be left completely untouched by cancel().");
    }
}
