<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P3 (docs/plans/branches-v2.md §4.6, D4): a branch's own
 * contact details, MoMo number and fees. Every column is nullable and null
 * means "inherit headquarters' value" (the companies row) — a branch only
 * overrides what differs, e.g. a cheaper reconnection fine in a town with
 * competition. Read through App\Models\Company::forBranch(). Kept on the
 * branches row itself (one set per branch) so a bill needs no extra join.
 *
 * `code` is a short tag for the branch (e.g. KBA) shown on documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique();
            $table->string('location', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tech_number', 30)->nullable();
            $table->string('momo_number', 30)->nullable();
            $table->string('momo_name', 50)->nullable();
            $table->decimal('reconnection_fine', 12, 2)->nullable();
            $table->decimal('relocation_fee', 12, 2)->nullable();
            $table->decimal('reinstallation_fee', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'location', 'phone', 'email', 'tech_number', 'momo_number', 'momo_name',
                'reconnection_fine', 'relocation_fee', 'reinstallation_fee',
            ]);
        });
    }
};
