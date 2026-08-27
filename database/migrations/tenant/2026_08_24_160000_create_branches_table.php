<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First step of multi-branch/multi-location support — see
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 3 (data model) and section 6 (migration & rollout).
 *
 * A branch is an ordinary table inside the tenant schema (not a Stancl
 * tenant boundary — see the doc's section 2), following the same dual-key
 * pattern (`id` + `uuid`) as every other tenant table.
 *
 * Seeds a single "Main Branch" row in the same migration that creates the
 * table (doc section 6 step 1) so every existing tenant has somewhere for
 * the follow-up zones.branch_id / companies.branch_id backfills to point.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 50)->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_branches_uuid');
        });

        DB::table('branches')->insert([
            'uuid' => DB::raw('gen_random_uuid()'),
            'name' => 'Main Branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
