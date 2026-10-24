<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 (docs/plans/branches-v2.md): Headquarters is itself a branch
 * row — HQ costs, HQ staff and HQ accounts belong to it, and money a town
 * sends up is a transfer into it. A single-branch tenant's only branch is its
 * headquarters, so it notices nothing.
 *
 * The existing "Main Branch" (or, failing that, the oldest branch) becomes
 * headquarters; a partial unique index keeps it to exactly one per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_headquarters')->default(false);
        });

        $headquartersId = DB::table('branches')->where('name', 'Main Branch')->value('id')
            ?? DB::table('branches')->orderBy('id')->value('id');

        if ($headquartersId !== null) {
            DB::table('branches')->where('id', $headquartersId)->update(['is_headquarters' => true]);
        }

        DB::statement('CREATE UNIQUE INDEX branches_one_headquarters ON branches (is_headquarters) WHERE is_headquarters');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS branches_one_headquarters');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_headquarters');
        });
    }
};
