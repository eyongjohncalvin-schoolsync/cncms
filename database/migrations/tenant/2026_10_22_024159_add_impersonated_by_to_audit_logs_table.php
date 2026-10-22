<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The landlord (central users.id) who was impersonating `user_id` when the
 * change was made — App\Support\Impersonation. Null for everything a user
 * did themselves, which is every existing row. Additive and nullable, so
 * existing data is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonated_by')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('impersonated_by');
        });
    }
};
