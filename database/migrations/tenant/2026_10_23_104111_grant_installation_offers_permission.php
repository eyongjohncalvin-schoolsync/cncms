<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installation fees, phase 2 (docs/plans/installation-and-promotions.md):
 * tops the new `installations.offers` permission (Settings → Installation
 * offers) up onto the existing `admin` system role, matching what
 * DefaultRolesSeeder seeds a fresh schema with (admin holds the whole
 * catalog). Managers only PICK an offer (installations.manage), they do not
 * set the company's offers (D8). Super bypasses every check.
 *
 * Same additive, idempotent shape as
 * 2026_10_22_190041_grant_installation_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'admin')->where('is_system', true)->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission' => Permission::InstallationsOffers->value,
        ]);
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::InstallationsOffers->value)->delete();
    }
};
