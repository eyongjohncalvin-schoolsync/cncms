<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second step of multi-branch/multi-location support — see
 * branches-and-locations.md section 6 step 1.
 *
 * Deliberately three separate safe stages in this one migration rather than
 * adding a NOT NULL column in a single shot: (1) add the column nullable,
 * (2) backfill every existing zone to the "Main Branch" row seeded by
 * 2026_08_24_160000_create_branches_table.php, (3) only then tighten to
 * NOT NULL now that every row is guaranteed to have a value.
 * restrictOnDelete() mirrors zones.zone_id's existing restrictOnDelete()
 * on customers — a branch with zones still assigned to it can't be deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->restrictOnDelete();
        });

        $mainBranchId = DB::table('branches')->where('name', 'Main Branch')->value('id');

        DB::table('zones')->whereNull('branch_id')->update(['branch_id' => $mainBranchId]);

        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
