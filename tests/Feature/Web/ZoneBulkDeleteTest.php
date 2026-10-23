<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Zones page bulk delete â€” ZoneController::previewBulkDestroy() /
 * bulkDestroy() over ZoneService::previewBulkDelete()/bulkDelete(), which
 * reuse ZoneService::delete() per zone. The tenant schema carries real
 * seeded zones, so every test names its own zones with a distinctive
 * prefix and only asserts about those.
 */
class ZoneBulkDeleteTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        Cache::flush();
    }

    private function actingAsRole(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<int, Zone>
     */
    private function zones(int $count, string $prefix = 'ZBULK', array $attributes = []): array
    {
        return collect(range(1, $count))
            ->map(fn (int $i): Zone => ZoneFactory::new()->create(['name' => sprintf('%s %02d', $prefix, $i), ...$attributes]))
            ->all();
    }

    public function test_deletes_some_selected_zones_and_leaves_the_rest(): void
    {
        $this->actingAsRole('manager');

        [$first, $second, $third] = $this->zones(3);

        $response = $this->post('/zones/bulk-delete', ['zone_uuids' => [$first->uuid, $second->uuid]]);

        $response->assertRedirect('/zones');
        $response->assertSessionHas('success', 'Deleted 2 zones.');
        $this->assertDatabaseMissing('zones', ['id' => $first->id]);
        $this->assertDatabaseMissing('zones', ['id' => $second->id]);
        $this->assertDatabaseHas('zones', ['id' => $third->id]);
    }

    public function test_select_all_matching_preview_then_delete_removes_every_matching_zone(): void
    {
        $this->actingAsRole('super');

        $zones = $this->zones(29, 'ZWRONGSEED');
        $other = ZoneFactory::new()->create(['name' => 'ZKEEPME 01']);

        $preview = $this->postJson('/zones/bulk-delete/preview', ['all_matching' => true, 'search' => 'ZWRONGSEED']);

        $preview->assertOk()
            ->assertJsonCount(29, 'zones')
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('zones.0.name', 'ZWRONGSEED 01')
            ->assertJsonPath('zones.0.kept_reason', null);

        $this->assertSame(29, Zone::query()->where('name', 'like', 'ZWRONGSEED%')->count(), 'preview must not delete anything');

        $response = $this->post('/zones/bulk-delete', ['zone_uuids' => collect($preview->json('zones'))->pluck('uuid')->all()]);

        $response->assertSessionHas('success', 'Deleted 29 zones.');
        $this->assertSame(0, Zone::query()->whereIn('id', collect($zones)->pluck('id'))->count());
        $this->assertDatabaseHas('zones', ['id' => $other->id]);
    }

    public function test_zones_in_use_are_kept_and_reported_while_the_rest_are_deleted(): void
    {
        $this->actingAsRole('super');

        [$free, $withCustomers, $withAgent] = $this->zones(3);
        CustomerFactory::new()->count(3)->create(['zone_id' => $withCustomers->id]);
        AgentFactory::new()->create(['zone_id' => $withAgent->id, 'user_id' => null]);

        $uuids = [$free->uuid, $withCustomers->uuid, $withAgent->uuid];

        $this->postJson('/zones/bulk-delete/preview', ['zone_uuids' => $uuids])
            ->assertOk()
            ->assertJsonPath('zones.0.kept_reason', null)
            ->assertJsonPath('zones.1.customer_count', 3)
            ->assertJsonPath('zones.1.kept_reason', '3 customers still assigned')
            ->assertJsonPath('zones.2.kept_reason', '1 agent still assigned');

        $response = $this->post('/zones/bulk-delete', ['zone_uuids' => $uuids]);

        $response->assertSessionHas(
            'success',
            'Deleted 1 zone. Kept 2 zones still in use: ZBULK 02 (3 customers still assigned), ZBULK 03 (1 agent still assigned).',
        );
        $this->assertDatabaseMissing('zones', ['id' => $free->id]);
        $this->assertDatabaseHas('zones', ['id' => $withCustomers->id]);
        $this->assertDatabaseHas('zones', ['id' => $withAgent->id]);
    }

    public function test_when_every_zone_is_kept_the_flash_is_an_error(): void
    {
        $this->actingAsRole('super');

        [$zone] = $this->zones(1);
        CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->post('/zones/bulk-delete', ['zone_uuids' => [$zone->uuid]])
            ->assertSessionHas('error', 'Deleted 0 zones. Kept 1 zone still in use: ZBULK 01 (1 customer still assigned).');

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_writes_an_audit_row_per_deleted_zone(): void
    {
        $this->actingAsRole('super');

        $zones = $this->zones(3);

        $this->post('/zones/bulk-delete', ['zone_uuids' => collect($zones)->pluck('uuid')->all()])->assertRedirect('/zones');

        foreach ($zones as $zone) {
            $this->assertSame(
                1,
                AuditLog::query()->where('table_name', 'zones')->where('record_uuid', $zone->uuid)->where('action', 'delete')->count(),
                "expected one delete audit row for {$zone->name}",
            );
        }
    }

    public function test_role_without_zone_delete_permission_is_forbidden(): void
    {
        $this->actingAsRole('agent');

        [$zone] = $this->zones(1);

        $this->post('/zones/bulk-delete', ['zone_uuids' => [$zone->uuid]])->assertForbidden();
        $this->postJson('/zones/bulk-delete/preview', ['zone_uuids' => [$zone->uuid]])->assertForbidden();

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_branch_fenced_manager_cannot_delete_another_branchs_zone(): void
    {
        $branchA = BranchFactory::new()->create();
        $branchB = BranchFactory::new()->create();
        [$zoneA] = $this->zones(1, 'ZBRANCHA', ['branch_id' => $branchA->id]);
        [$zoneB] = $this->zones(1, 'ZBRANCHB', ['branch_id' => $branchB->id]);

        $this->actingAsRole('manager', $branchA->id);

        $this->post('/zones/bulk-delete', ['zone_uuids' => [$zoneA->uuid, $zoneB->uuid]])
            ->assertSessionHasErrors('zone_uuids');

        $this->postJson('/zones/bulk-delete/preview', ['zone_uuids' => [$zoneB->uuid]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('zone_uuids');

        $this->postJson('/zones/bulk-delete/preview', ['all_matching' => true, 'search' => 'ZBRANCH'])
            ->assertOk()
            ->assertJsonCount(1, 'zones')
            ->assertJsonPath('zones.0.uuid', $zoneA->uuid);

        $this->assertDatabaseHas('zones', ['id' => $zoneA->id]);
        $this->assertDatabaseHas('zones', ['id' => $zoneB->id]);
    }

    public function test_empty_selection_is_rejected(): void
    {
        $this->actingAsRole('super');

        $this->post('/zones/bulk-delete', ['zone_uuids' => []])->assertSessionHasErrors('zone_uuids');
        $this->post('/zones/bulk-delete', [])->assertSessionHasErrors('zone_uuids');
        $this->postJson('/zones/bulk-delete/preview', [])->assertJsonValidationErrors('zone_uuids');
    }

    public function test_unknown_uuids_are_rejected_and_nothing_is_deleted(): void
    {
        $this->actingAsRole('super');

        [$zone] = $this->zones(1);

        $this->post('/zones/bulk-delete', ['zone_uuids' => [$zone->uuid, (string) Str::uuid()]])
            ->assertSessionHasErrors('zone_uuids');
        $this->post('/zones/bulk-delete', ['zone_uuids' => ['not-a-uuid']])
            ->assertSessionHasErrors('zone_uuids.0');

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_more_than_the_cap_is_rejected(): void
    {
        $this->actingAsRole('super');

        $uuids = collect(range(1, 501))->map(fn (): string => (string) Str::uuid())->all();

        $this->post('/zones/bulk-delete', ['zone_uuids' => $uuids])
            ->assertSessionHasErrors(['zone_uuids' => 'At most 500 zones can be deleted at once.']);
        $this->postJson('/zones/bulk-delete/preview', ['zone_uuids' => $uuids])
            ->assertJsonValidationErrors('zone_uuids');
    }

    public function test_duplicate_uuids_are_rejected(): void
    {
        $this->actingAsRole('super');

        [$zone] = $this->zones(1);

        $this->post('/zones/bulk-delete', ['zone_uuids' => [$zone->uuid, $zone->uuid]])
            ->assertSessionHasErrors('zone_uuids.0');

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }
}
