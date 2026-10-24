<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock (Resources phase 3) — every decoder and every metre of cable has
     * one place at every moment:
     *
     * - stock_items: what is stocked; `serial` items (decoders, routers) are
     *   tracked one by one, `bulk` items by quantity (cable in metres).
     * - stock_locations: the store, one bag per technician, and the "other
     *   ends" of a movement (customers, suppliers, written-off), so every
     *   movement has a from and a to.
     * - stock_movements: the append-only, hash-sealed ledger of every
     *   movement (database triggers refuse edits and deletes).
     * - stock_balances: cached quantity per location and item, kept in step
     *   with the ledger in the same transaction, re-checked nightly.
     * - serial_units: each serialised unit's current place and status.
     * - stock_transfers (+ lines): store → technician (and back), confirmed by
     *   the receiver; nothing moves until then.
     * - stock_jobs (+ lines): installations and repairs — the only way stock
     *   reaches a customer — linked to the customer, complaint and service.
     * - stock_adjustments: damaged / lost / found / corrections, approved by
     *   someone else before they post.
     * - stock_counts (+ lines): blind stocktakes, never by the holder.
     * - stock_norms: expected use per job (e.g. 30 m of cable per
     *   installation) — usage above them is flagged.
     */
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('sku', 40)->unique();
            $table->string('barcode', 80)->nullable()->unique();
            $table->string('name', 120);
            $table->string('category', 30);
            $table->string('tracking', 10);
            $table->string('unit', 10);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_items ADD CONSTRAINT stock_items_tracking_check CHECK (tracking IN ('serial', 'bulk'))");
        DB::statement("ALTER TABLE stock_items ADD CONSTRAINT stock_items_unit_check CHECK (unit IN ('piece', 'metre', 'roll', 'box'))");
        DB::statement("ALTER TABLE stock_items ADD CONSTRAINT stock_items_serial_piece CHECK (tracking = 'bulk' OR unit = 'piece')");

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('type', 20);
            $table->string('name', 120);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_type_check CHECK (type IN ('store', 'technician', 'customer', 'supplier', 'adjustment'))");
        DB::statement('ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_user_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
        DB::statement("CREATE UNIQUE INDEX stock_locations_one_bag_per_user ON stock_locations (user_id) WHERE type = 'technician'");
        DB::statement("CREATE UNIQUE INDEX stock_locations_one_per_customer ON stock_locations (customer_id) WHERE type = 'customer'");
        DB::statement("CREATE UNIQUE INDEX stock_locations_one_virtual ON stock_locations (type) WHERE type IN ('supplier', 'adjustment')");

        Schema::create('serial_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->string('serial_no', 80);
            $table->string('status', 30);
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestampTz('status_changed_at')->useCurrent();
            $table->timestampsTz();

            $table->unique(['item_id', 'serial_no'], 'serial_units_item_serial_unique');
            $table->index(['location_id', 'status'], 'idx_serial_units_location');
            $table->index('customer_id', 'idx_serial_units_customer');
        });

        DB::statement("ALTER TABLE serial_units ADD CONSTRAINT serial_units_status_check CHECK (status IN ('in_store', 'with_technician', 'installed', 'faulty', 'returned_to_supplier', 'lost'))");

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 30);
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->foreignId('serial_unit_id')->nullable()->constrained('serial_units')->restrictOnDelete();
            $table->string('document_type', 30)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->foreignId('reverses_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['item_id', 'occurred_at'], 'idx_stock_movements_item');
            $table->index(['document_type', 'document_id'], 'idx_stock_movements_document');
            $table->index('from_location_id', 'idx_stock_movements_from');
            $table->index('to_location_id', 'idx_stock_movements_to');
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_distinct_ends CHECK (from_location_id <> to_location_id)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_serial_one CHECK (serial_unit_id IS NULL OR qty = 1)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_performed_by_foreign FOREIGN KEY (performed_by) REFERENCES public.users(id)');
        DB::statement('CREATE UNIQUE INDEX stock_movements_reversed_once ON stock_movements (reverses_id) WHERE reverses_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Stock movements are append-only; post a reversing movement instead.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_immutable();
            CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_immutable();
        SQL);

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('qty', 12, 2)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['location_id', 'item_id']);
            $table->index('item_id', 'idx_stock_balances_item');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->uuid('local_uuid')->nullable()->unique();
            $table->timestampsTz();

            $table->index(['to_location_id', 'status'], 'idx_stock_transfers_to');
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled'))");

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->foreignId('serial_unit_id')->nullable()->constrained('serial_units')->restrictOnDelete();
        });

        Schema::create('stock_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('local_uuid')->nullable()->unique();
            $table->string('job_type', 20);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('complaint_id')->nullable()->constrained('complaints')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->unsignedBigInteger('technician_id');
            $table->timestampTz('completed_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('flags')->default(DB::raw("'[]'::jsonb"));
            $table->timestampsTz();

            $table->index(['technician_id', 'completed_at'], 'idx_stock_jobs_technician');
            $table->index('customer_id', 'idx_stock_jobs_customer');
        });

        DB::statement("ALTER TABLE stock_jobs ADD CONSTRAINT stock_jobs_type_check CHECK (job_type IN ('installation', 'repair', 'relocation', 'recovery'))");
        DB::statement('ALTER TABLE stock_jobs ADD CONSTRAINT stock_jobs_technician_foreign FOREIGN KEY (technician_id) REFERENCES public.users(id)');

        Schema::create('stock_job_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('stock_jobs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->foreignId('serial_unit_id')->nullable()->constrained('serial_units')->restrictOnDelete();
            $table->string('direction', 10)->default('used');
        });

        DB::statement("ALTER TABLE stock_job_lines ADD CONSTRAINT stock_job_lines_direction_check CHECK (direction IN ('used', 'recovered'))");

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('serial_unit_id')->nullable()->constrained('serial_units')->restrictOnDelete();
            $table->string('direction', 3);
            $table->decimal('qty', 12, 2);
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_reason_check CHECK (reason IN ('damaged', 'lost', 'theft', 'found', 'faulty', 'correction'))");
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('counted_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_status_check CHECK (status IN ('open', 'submitted', 'approved', 'rejected'))");

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('expected_qty', 12, 2);
            $table->decimal('counted_qty', 12, 2)->nullable();
            $table->jsonb('serials')->nullable();

            $table->unique(['count_id', 'item_id'], 'stock_count_lines_once');
        });

        Schema::create('stock_norms', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 20);
            $table->foreignId('item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->decimal('expected_qty', 12, 2);
            $table->unsignedSmallInteger('tolerance_pct')->default(50);
            $table->timestampsTz();

            $table->unique(['job_type', 'item_id'], 'stock_norms_once');
        });

        foreach (['stock_transfers' => ['created_by', 'decided_by'], 'stock_adjustments' => ['requested_by', 'decided_by'], 'stock_counts' => ['counted_by', 'decided_by']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_norms');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_job_lines');
        Schema::dropIfExists('stock_jobs');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_balances');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements;
            DROP TRIGGER IF EXISTS stock_movements_no_delete ON stock_movements;
            DROP FUNCTION IF EXISTS stock_movements_immutable();
        SQL);

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('serial_units');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('stock_items');
    }
};
