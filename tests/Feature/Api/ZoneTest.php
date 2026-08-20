<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via ZoneFactory;
 * none of the real seeded 29 zones are read or modified.
 */
class ZoneTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_index_lists_zones(): void
    {
        ZoneFactory::new()->create();
        ZoneFactory::new()->create();

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/zones');

        $response->assertOk()->assertJsonStructure([
            'data' => [['uuid', 'name', 'town']],
            'meta',
        ]);
    }

    public function test_show_returns_a_zone(): void
    {
        $zone = ZoneFactory::new()->create();

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/zones/{$zone->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $zone->uuid)
            ->assertJsonPath('data.name', $zone->name);
    }

    public function test_super_can_create_a_zone(): void
    {
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/zones', [
                'name' => 'Z99 (NEW ZONE)',
                'town' => 'KUMBA 3',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Z99 (NEW ZONE)');

        $this->assertDatabaseHas('zones', ['name' => 'Z99 (NEW ZONE)']);
    }

    public function test_super_can_update_a_zone(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/zones/{$zone->uuid}", [
                'town' => 'UPDATED TOWN',
            ]);

        $response->assertOk()->assertJsonPath('data.town', 'UPDATED TOWN');
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'town' => 'UPDATED TOWN']);
    }

    public function test_super_can_delete_a_zone(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/zones/{$zone->uuid}");

        $response->assertOk()->assertJson(['message' => 'Zone deleted']);
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    public function test_store_fails_validation_when_name_is_missing(): void
    {
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/zones', ['town' => 'KUMBA 3']);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_agent_cannot_create_a_zone(): void
    {
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/zones', [
                'name' => 'Z98 (SHOULD NOT EXIST)',
                'town' => 'KUMBA 3',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('zones', ['name' => 'Z98 (SHOULD NOT EXIST)']);
    }
}
