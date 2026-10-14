<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt & bill delivery:
     * - customers.email / customers.preferred_channel — where a customer
     *   wants their receipts: whatsapp (default; staff send from the
     *   "waiting to be sent" list), email (sent automatically when enabled),
     *   or print (handed over on paper).
     * - notification_settings.auto_send_receipts — email a receipt the
     *   moment its payment is verified, for customers who prefer email.
     * - manuscripts.sent_log — like payment_receipts.sent_log: one entry per
     *   time the month's bill was sent, so the bills waiting list knows what
     *   is still to send.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 150)->nullable();
            $table->string('preferred_channel', 20)->default('whatsapp');
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_preferred_channel_check CHECK (preferred_channel IN ('whatsapp', 'email', 'print'))");

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('auto_send_receipts')->default(false);
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->jsonb('sent_log')->default(DB::raw("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropColumn('sent_log');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('auto_send_receipts');
        });

        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_preferred_channel_check');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['email', 'preferred_channel']);
        });
    }
};
