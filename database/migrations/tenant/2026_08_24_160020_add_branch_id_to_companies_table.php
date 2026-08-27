<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Third step of multi-branch/multi-location support — see
 * branches-and-locations.md section 6 step 2 and section 3's note on
 * `companies` becoming branch-scoped.
 *
 * Same nullable-then-backfill pattern as
 * 2026_08_24_160010_add_branch_id_to_zones_table.php, but deliberately
 * LEFT NULLABLE (not tightened to NOT NULL): companies becoming
 * branch-scoped is forward-looking — each branch could get its own Company
 * row later — and forcing NOT NULL now would be premature given only one
 * Company row exists per tenant today. nullOnDelete() (rather than
 * restrictOnDelete()) matches that optionality: deleting a branch should
 * not be blocked by a still-nullable company reference.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
        });

        $mainBranchId = DB::table('branches')->where('name', 'Main Branch')->value('id');

        DB::table('companies')->whereNull('branch_id')->update(['branch_id' => $mainBranchId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
