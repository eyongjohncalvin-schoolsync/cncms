<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_activitylogs', function (Blueprint $table) {
            $table->id();
            // user_id is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('last_activity')->nullable();
            $table->string('lockout_token')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('device_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('user_id');
        });

        // Cross-schema FK: see the same note in the payment_verifications migration —
        // tenant search_path does not implicitly include `public`, so the target is
        // schema-qualified explicitly in a raw statement. ON DELETE CASCADE per the
        // reference doc.
        DB::statement('ALTER TABLE user_activitylogs ADD CONSTRAINT user_activitylogs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activitylogs');
    }
};
