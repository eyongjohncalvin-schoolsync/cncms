<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P4 (docs/plans/branches-v2.md §4.5, D8): headquarters defines
 * salary grades with a range; a branch sets each worker's salary inside it,
 * and a salary outside the range waits for headquarters' approval (the
 * previous salary stays in force meanwhile).
 *
 *  - salary_grades: the HQ scale (name, min, max).
 *  - staff_salaries: every salary decision, append-only — the latest
 *    `approved` row per person is their salary; `pending_hq` waits for
 *    headquarters; `rejected` is kept for the record.
 *
 * Existing agents' salaries (agents.salary) are carried in as approved rows,
 * so nobody's pay changes; agents.salary stays the mirror the mobile app reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_grades', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 60)->unique();
            $table->decimal('min_amount', 12, 2);
            $table->decimal('max_amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE salary_grades ADD CONSTRAINT salary_grades_range CHECK (min_amount >= 0 AND max_amount >= min_amount)');

        Schema::create('staff_salaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Cross-schema to central public.users — no FK (same as agents.user_id).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('salary_grade_id')->nullable()->constrained('salary_grades')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('effective_from');
            $table->string('status', 20);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('set_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['user_id', 'status', 'id']);
            $table->index(['status', 'branch_id']);
        });

        DB::statement("ALTER TABLE staff_salaries ADD CONSTRAINT staff_salaries_status CHECK (status IN ('approved', 'pending_hq', 'rejected'))");
        DB::statement('ALTER TABLE staff_salaries ADD CONSTRAINT staff_salaries_amount CHECK (amount > 0)');
        // One salary waiting for headquarters per person at a time.
        DB::statement("CREATE UNIQUE INDEX staff_salaries_one_pending ON staff_salaries (user_id) WHERE status = 'pending_hq'");

        DB::statement("INSERT INTO staff_salaries (user_id, branch_id, amount, effective_from, status, reason)
            SELECT DISTINCT ON (a.user_id) a.user_id, a.branch_id, a.salary, COALESCE(a.created_at::date, CURRENT_DATE), 'approved', 'Salary before salary grades'
            FROM agents a
            WHERE a.user_id IS NOT NULL AND a.salary > 0
            ORDER BY a.user_id, a.id");
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_salaries');
        Schema::dropIfExists('salary_grades');
    }
};
