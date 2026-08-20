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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // The reference doc types this INT ("references landlord.tenants.id"), but the
            // central tenants table (Stancl default) has a STRING/UUID primary key, not an
            // integer one — see 2019_09_15_000010_create_tenants_table.php. Typed as string
            // here to actually match. No FK is declared, matching the reference doc, which
            // only comments the relationship rather than enforcing it.
            $table->string('tenant_id');
            $table->string('table_name', 100);
            $table->uuid('record_uuid');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->enum('action', ['create', 'update', 'delete']);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            // user_id is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('table_name', 'idx_audit_table');
            $table->index('record_uuid', 'idx_audit_record');
            $table->index('user_id', 'idx_audit_user');
            $table->index('action', 'idx_audit_action');
            $table->index('created_at', 'idx_audit_created');
            $table->index('tenant_id', 'idx_audit_tenant');
            $table->index('new_values', 'idx_audit_jsonb_new', 'gin');
            $table->index('old_values', 'idx_audit_jsonb_old', 'gin');
        });

        // Cross-schema FK: see the same note in the payment_verifications migration —
        // tenant search_path does not implicitly include `public`, so the target is
        // schema-qualified explicitly in a raw statement.
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');

        // Append-only table: no Blueprint-fluent equivalent for a Postgres RULE.
        DB::statement('CREATE RULE audit_no_delete AS ON DELETE TO audit_logs DO INSTEAD NOTHING');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
