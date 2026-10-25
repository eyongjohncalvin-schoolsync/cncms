<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many customers one field agent should look after before management is
 * told they are busy or overloaded (the Zone coverage screen). Information
 * only — never a block; management decides. Headquarters' value is the
 * default, a branch may set its own (App\Models\Branch::OVERRIDABLE), and
 * empty everywhere means no limit is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedInteger('agent_customer_limit')->nullable();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->unsignedInteger('agent_customer_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('agent_customer_limit');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('agent_customer_limit');
        });
    }
};
