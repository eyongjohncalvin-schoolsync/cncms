<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3 of the permission audit (owner, 2026-11-02): "limited to their own
 * zones" is a setting on the role, not the role being named `agent` — so a
 * Collectors or Technicians role can work zones too. The agent role keeps
 * it, so nothing changes today.
 *
 * Verifying and disconnecting inside one's own zones were also granted to
 * the `agent` role by name; they become permissions, given to the agent role
 * (the only one that could do them before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('zone_limited')->default(false);
        });

        DB::table('roles')->where('name', 'agent')->update(['zone_limited' => true]);

        foreach (DB::table('roles')->where('name', 'agent')->pluck('id') as $roleId) {
            foreach ([Permission::PaymentsVerifyZone->value, Permission::CustomersDisconnectZone->value] as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [Permission::PaymentsVerifyZone->value, Permission::CustomersDisconnectZone->value])->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('zone_limited');
        });
    }
};
