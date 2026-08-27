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
     *
     * See references/complaint-desk.md section 2 for the full field-by-field
     * rationale. `submitted_by`/`assigned_to`/`resolved_by` are cross-schema
     * FKs into the central public.users table — same raw DB::statement
     * pattern as expenditures.user_id / payment_verifications.verified_by
     * (tenant search_path does not implicitly include `public`, so
     * foreignId()->constrained() can't target it from a Blueprint closure).
     * `duplicate_of_id` is a same-schema self-referencing FK, so it uses the
     * normal fluent constraint.
     */
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->enum('category', ['operational', 'customer']);
            $table->string('title');
            $table->text('description');
            $table->boolean('urgent')->default(false);
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');

            // Cross-schema FKs — see class doc above.
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();

            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            // Set once by the escalation checker (not built in this pass —
            // see references/task-scheduler.md section 5) the first time the
            // 48h threshold fires. Left here, unpopulated, so that work can
            // plug straight in without a schema change.
            $table->timestampTz('escalated_at')->nullable();

            $table->foreignId('duplicate_of_id')->nullable()->constrained('complaints')->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_complaints_uuid');
            // Composite index for the escalation checker's sweep query
            // (references/complaint-desk.md section 2's exact spec: scans
            // open/in_progress complaints ordered by age).
            $table->index(['status', 'created_at'], 'idx_complaints_status_created');
            $table->index('category', 'idx_complaints_category');
            $table->index('customer_id', 'idx_complaints_customer');
            $table->index('zone_id', 'idx_complaints_zone');
            $table->index('submitted_by', 'idx_complaints_submitted_by');
            $table->index('assigned_to', 'idx_complaints_assigned_to');
            $table->index('resolved_by', 'idx_complaints_resolved_by');
            $table->index('duplicate_of_id', 'idx_complaints_duplicate_of');
        });

        // Cross-schema FKs: see the same note in the expenditures /
        // payment_verifications migrations — the target is schema-qualified
        // explicitly in a raw statement since tenant search_path doesn't
        // implicitly include `public`.
        DB::statement('ALTER TABLE complaints ADD CONSTRAINT complaints_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE complaints ADD CONSTRAINT complaints_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE complaints ADD CONSTRAINT complaints_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
