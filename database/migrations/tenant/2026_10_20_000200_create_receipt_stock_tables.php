<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash controls wave 2 (docs/plans/cash-controls.md) — receipt numbers
     * only the system creates, so every receipt can be authenticated:
     *
     * - field_devices — a collector's phone. Registration hands the phone a
     *   secret (stored encrypted here) it uses to sign receipts while
     *   offline; revoking a lost phone stops its codes being accepted.
     * - receipt_blocks — a contiguous range of receipt numbers handed to ONE
     *   person: `digital` (the phone prints them, signed with its device
     *   secret) or `book` (a printed book of numbered leaves with a QR each).
     * - receipt_leaves — one row per number in a block, so every number can
     *   be accounted for: unused → used (linked to its receipt) or
     *   cancelled (with a reason). A number scanned by a customer while still
     *   unused is stamped `scanned_unused_at` — money taken but not recorded.
     * - payment_receipts gains the security code printed on it, where it came
     *   from (office / digital / book), and the leaf + device for field ones.
     *
     * User columns are cross-schema FKs into public.users, added with raw
     * statements like every other user column on tenant tables.
     */
    public function up(): void
    {
        Schema::create('field_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->text('secret');
            $table->timestampTz('registered_at')->useCurrent();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('user_id', 'idx_field_devices_user');
        });

        DB::statement('ALTER TABLE field_devices ADD CONSTRAINT field_devices_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE field_devices ADD CONSTRAINT field_devices_revoked_by_foreign FOREIGN KEY (revoked_by) REFERENCES public.users(id)');

        Schema::create('receipt_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 20)->unique();
            $table->enum('kind', ['digital', 'book']);
            $table->unsignedBigInteger('holder_user_id');
            $table->foreignId('field_device_id')->nullable()->constrained('field_devices')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('first_sequence');
            $table->unsignedInteger('last_sequence');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['holder_user_id', 'status'], 'idx_receipt_blocks_holder_status');
        });

        DB::statement('ALTER TABLE receipt_blocks ADD CONSTRAINT receipt_blocks_holder_user_id_foreign FOREIGN KEY (holder_user_id) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE receipt_blocks ADD CONSTRAINT receipt_blocks_allocated_by_foreign FOREIGN KEY (allocated_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE receipt_blocks ADD CONSTRAINT receipt_blocks_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES public.users(id)');

        Schema::create('receipt_leaves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('receipt_block_id')->constrained('receipt_blocks')->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->enum('status', ['unused', 'used', 'cancelled'])->default('unused');
            $table->timestampTz('used_at')->nullable();
            $table->string('cancelled_reason', 255)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('scanned_unused_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['receipt_block_id', 'status'], 'idx_receipt_leaves_block_status');
        });

        DB::statement('ALTER TABLE receipt_leaves ADD CONSTRAINT receipt_leaves_cancelled_by_foreign FOREIGN KEY (cancelled_by) REFERENCES public.users(id)');

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('source', 10)->default('office');
            $table->string('security_code', 12)->nullable();
            $table->foreignId('receipt_leaf_id')->nullable()->unique()->constrained('receipt_leaves')->restrictOnDelete();
            $table->foreignId('field_device_id')->nullable()->constrained('field_devices')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_source_check CHECK (source IN ('office', 'digital', 'book'))");

        Schema::table('notification_settings', function (Blueprint $table) {
            // Email every customer with an address the moment money is
            // recorded for them — the anti-fraud confirmation (a customer
            // who paid but got no email calls the office). Independent of
            // `auto_send_receipts`, which follows the preferred channel.
            $table->boolean('email_collection_confirmations')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('email_collection_confirmations');
        });

        DB::statement('ALTER TABLE payment_receipts DROP CONSTRAINT IF EXISTS payment_receipts_source_check');

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_device_id');
            $table->dropConstrainedForeignId('receipt_leaf_id');
            $table->dropColumn(['source', 'security_code']);
        });

        Schema::dropIfExists('receipt_leaves');
        Schema::dropIfExists('receipt_blocks');
        Schema::dropIfExists('field_devices');
    }
};
