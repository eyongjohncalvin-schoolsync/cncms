<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisite for adding `use Auditable;` to App\Models\TenantUser (see
 * that model's doc comment) — App\Observers\AuditableObserver::writeAudit()
 * reads $model->uuid for the NOT NULL audit_logs.record_uuid column on every
 * auditable model (Agent, Customer, Payment, Zone, ... all already carry
 * one via App\Models\Concerns\HasUuid). tenant_users never needed a uuid
 * before now — every existing route/controller/frontend call
 * (SettingsUserController, Settings/Users.tsx) addresses a TenantUser by its
 * plain `id`, and that is deliberately left unchanged here: this column
 * backs audit-log identity only, it does NOT become the route-binding key
 * (no #[RouteKey('uuid')] is added to the model), so nothing about how a
 * TenantUser is looked up elsewhere in the app changes.
 *
 * ->default(DB::raw('gen_random_uuid()')) mirrors
 * 2026_08_20_040701_add_uuid_username_status_to_users_table.php's identical
 * retroactive-backfill pattern — Postgres computes the volatile default for
 * every pre-existing row, not just new ones, so no separate backfill step is
 * needed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'))->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
