<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P4 (docs/plans/branches-v2.md §4.4, D7): where each person has
 * worked, in which role, from when to when — "who was Buea's cashier in
 * March?". One open row (ends_on NULL) per person; hiring opens it, a branch
 * or role change closes it and opens the next. tenant_users.branch_id stays
 * the fast fence; this is the history. Work done (payments, bons, cash) keeps
 * its own branch_id, so a transfer never moves past figures.
 *
 * branch_id NULL = not fenced to a branch (owner, HQ-level staff).
 * Backfilled with one open row per current staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Cross-schema to central public.users — no FK (same as agents.user_id).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('role', 50);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'starts_on']);
            $table->index(['branch_id', 'starts_on']);
        });

        // At most one open assignment per person.
        DB::statement('CREATE UNIQUE INDEX staff_assignments_one_open ON staff_assignments (user_id) WHERE ends_on IS NULL');

        // An agent works in their zone's branch; everyone else in their fence.
        DB::statement('INSERT INTO staff_assignments (user_id, branch_id, role, starts_on)
            SELECT DISTINCT ON (tu.user_id)
                   tu.user_id,
                   COALESCE((SELECT z.branch_id FROM agents a JOIN zones z ON z.id = a.zone_id WHERE a.user_id = tu.user_id ORDER BY a.id LIMIT 1), tu.branch_id),
                   tu.role,
                   COALESCE(tu.created_at::date, CURRENT_DATE)
            FROM tenant_users tu
            ORDER BY tu.user_id, tu.id');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignments');
    }
};
