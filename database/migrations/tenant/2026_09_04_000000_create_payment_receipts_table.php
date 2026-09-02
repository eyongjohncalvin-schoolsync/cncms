<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment receipts (docs/plans/payment-receipts-and-whatsapp.md, Wave 1).
     *
     * A `payment_receipts` row is the receipt the BUSINESS issues to the
     * customer for a recorded payment — a distinct concept from
     * `payment_verifications.receipt_photo_path` (proof-of-payment evidence
     * an agent uploads DURING verification, kept as-is). It is auto-issued
     * the moment a payment reaches `verification_status = 'verified'` (see
     * App\Services\PaymentVerificationService::verify() ->
     * App\Services\PaymentReceiptService::issueFor()) and voided — never
     * deleted — if that payment is later rejected.
     *
     *   payment_id     one receipt per payment (UNIQUE) — the hard DB
     *                  guarantee behind issueFor()'s idempotency.
     *   receipt_number RCP-{YYYY}-{6-digit} — allocated from `receipt_counters`
     *                  (below) under a row lock, so two concurrent verify()
     *                  calls can never mint the same number. Gap-tolerant: a
     *                  rolled-back allocation burns a number, which is fine.
     *   snapshot       jsonb — customer name/phone/zone/branch, the period(s)
     *                  the payment covers, method/frequency/months, amount,
     *                  momo_ref, and the company header (name / MOMO numbers /
     *                  contact) frozen at issue time, so a later customer edit
     *                  or manuscript recalc never changes an issued receipt.
     *   pdf_path/disk  a lazily-rendered, regenerable dompdf cache — always
     *                  re-rendered from `snapshot`, never from live data.
     *   sent_log       jsonb array — Wave 3 appends {channel, at, by, to} per
     *                  WhatsApp send.
     *   status         issued | void.
     *
     * `issued_by` is a cross-schema FK into public.users — the tenant
     * search_path does not implicitly include `public`, so it is added via a
     * raw DB::statement exactly like bill_batches.generated_by,
     * command_runs.published_by and arrears_adjustments.requested_by.
     */
    public function up(): void
    {
        // One counter row per calendar year, per tenant schema. The receipt
        // number allocator (PaymentReceiptService::allocateNumber()) takes a
        // `FOR UPDATE` lock on the row for the current year, reads
        // `next_number`, and increments it — serialising concurrent
        // allocations without a dedicated Postgres SEQUENCE (which would need
        // its own per-tenant DDL and can't be reset/inspected as easily).
        Schema::create('receipt_counters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            // One receipt per payment. cascadeOnDelete: a hard-deleted payment
            // (super/admin only) takes its receipt with it, same as
            // payment_verifications.
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();

            $table->string('receipt_number', 32)->unique();
            $table->timestampTz('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->decimal('amount', 12, 2);

            $table->string('pdf_path')->nullable();
            $table->string('pdf_disk', 32)->nullable();

            $table->jsonb('snapshot');
            $table->jsonb('sent_log')->default(DB::raw("'[]'::jsonb"));
            $table->string('status', 20)->default('issued'); // issued | void

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_payment_receipts_uuid');
            $table->index('receipt_number', 'idx_payment_receipts_number');
            $table->index('status', 'idx_payment_receipts_status');
        });

        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_issued_by_foreign FOREIGN KEY (issued_by) REFERENCES public.users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_receipts')) {
            DB::statement('ALTER TABLE payment_receipts DROP CONSTRAINT IF EXISTS payment_receipts_issued_by_foreign');
        }

        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('receipt_counters');
    }
};
