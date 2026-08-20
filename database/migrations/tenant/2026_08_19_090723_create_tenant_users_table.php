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
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            // user_id is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('user_id');
            // The reference doc types this INT ("references public.tenants.id"), but the
            // central tenants table (Stancl default) has a STRING/UUID primary key, not an
            // integer one — see 2019_09_15_000010_create_tenants_table.php. Typed as string
            // here to actually match. No FK is declared, matching the reference doc, which
            // only comments the relationship rather than enforcing it (and would itself be
            // a cross-schema FK).
            $table->string('tenant_id');
            $table->enum('role', ['super', 'admin', 'manager', 'agent', 'worker'])->default('agent');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['user_id', 'tenant_id']);
            $table->index('role', 'idx_tenant_users_role');
            $table->index('user_id', 'idx_tenant_users_user');
        });

        // Cross-schema FK: see the same note in the payment_verifications migration —
        // tenant search_path does not implicitly include `public`, so the target is
        // schema-qualified explicitly in a raw statement.
        DB::statement('ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
