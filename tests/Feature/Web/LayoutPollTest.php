<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * AppLayout's minute poll (bell + maintenance banner) is answered by
 * App\Http\Middleware\AnswerLayoutPoll without running the page controller
 * (query audit, 2026-10-27); any other partial reload still runs it.
 */
class LayoutPollTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'admin', 'branch_id' => null]);
        $this->actingAs($this->kelvin);
    }

    public function test_the_layout_poll_gets_the_bell_and_banner_without_the_page(): void
    {
        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();

        $response = $this->get('/dashboard', $this->pollHeaders('notifications,maintenance'))->assertOk();

        $queries = DB::connection('tenant')->getQueryLog();
        DB::connection('tenant')->disableQueryLog();

        $this->assertSame('Dashboard', $response->json('component'));
        $this->assertArrayHasKey('notifications', $response->json('props'));
        $this->assertArrayHasKey('maintenance', $response->json('props'));
        $this->assertArrayNotHasKey('stats', $response->json('props'), 'The page controller did not run.');
        $this->assertEmpty(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "customers"')), 'No dashboard queries.');
    }

    public function test_any_other_partial_reload_still_runs_the_page(): void
    {
        $this->get('/dashboard', $this->pollHeaders('stats'))
            ->assertOk()
            ->assertJsonStructure(['props' => ['stats']]);
    }

    public function test_a_deactivated_users_poll_is_refused(): void
    {
        $this->kelvin->forceFill(['status' => 'passive'])->save();

        // Signed out and sent to the login page, as for any other request.
        $this->get('/dashboard', $this->pollHeaders('notifications'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * @return array<string, string>
     */
    private function pollHeaders(string $only): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => $only,
        ];
    }
}
