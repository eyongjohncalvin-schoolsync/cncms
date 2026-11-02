<?php

declare(strict_types=1);

use App\Auth\PermissionSplits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wave 2 of the permission audit (owner, 2026-11-02): features that
 * borrowed another area's permission, or a role name, get their own. Every
 * existing role is given each new permission exactly when it could do that
 * thing before (App\Auth\PermissionSplits), so nobody gains or loses
 * anything today; from now on each is ticked in Settings → Roles.
 *
 * `arrears.view` was seeded to every role but never checked; it now opens
 * the arrears adjustments queue, so it stays only with the roles that could
 * open that queue before (arrears.approve or audit.view).
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->where('is_super', false)->get(['id', 'name']);

        foreach ($roles as $role) {
            $held = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->all();
            $granted = array_diff(PermissionSplits::derive($held), $held);

            if ($role->name === 'admin') {
                $granted = [...$granted, ...PermissionSplits::ADMIN_ONLY];
            }

            foreach (array_unique($granted) as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission' => $permission]);
            }

            if (array_intersect(['arrears.approve', 'audit.view'], $held) === []) {
                DB::table('role_permissions')->where('role_id', $role->id)->where('permission', 'arrears.view')->delete();
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', [...array_keys(PermissionSplits::FROM), ...PermissionSplits::ADMIN_ONLY, 'expenditures.close_months'])
            ->delete();
    }
};
