<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manuscript-run-management feature (`.claude/skills/cncms-context/references/task-scheduler.md`'s
     * 2026-08-28 addendum): links a `manuscripts` row back to the exact
     * `command_runs` row (`command = 'manuscript:calculate'`) that wrote it,
     * so a Delete/Rollback action can scope a real `DELETE ... WHERE
     * command_run_id = ?` to precisely the rows THIS run created/overwrote —
     * never by `period` alone, which would also delete rows written by a
     * different run against the same period (e.g. a prior month's run that
     * already published, later re-run and re-published by a second,
     * different command_runs row for the identical period string).
     *
     * Nullable and `nullOnDelete()`: every manuscripts row written BEFORE
     * this migration (the entire pre-existing history, ~521+ rows) has no
     * originating run to attribute — left NULL rather than backfilled with a
     * guess, since there is no reliable way to reconstruct which historical
     * command_runs row (if any) actually wrote a given pre-migration row.
     * Rollback's own guard only deletes rows with a matching command_run_id,
     * so a NULL-linked historical row is simply never a rollback candidate —
     * the safe default. `nullOnDelete()` (not cascade) because a
     * command_runs row is never actually deleted by anything in this app
     * (rollback/cancel both just flip `status`); this is defensive symmetry
     * with how `scheduled_task_id`'s FK on command_runs itself already
     * behaves (see 2026_08_25_200010_add_scheduling_fields_to_command_runs_table.php),
     * not a behavior this app currently exercises.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('command_run_id')->nullable()->after('period')
                ->constrained('command_runs')->nullOnDelete();

            $table->index('command_run_id', 'idx_manuscripts_command_run');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('command_run_id');
        });
    }
};
