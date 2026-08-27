<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends the previously-dormant `messages` table (bill-notifications.md
     * section 6.3) to record which channel a message was sent over —
     * 'whatsapp', 'sms', or 'email'. Validated in application code
     * (App\Services\BillNotificationService and friends), not a DB enum, to
     * match this codebase's existing convention for similar string-status
     * columns (e.g. `messages.status`, `uploads.type`). Defaults to 'sms'
     * for backward compatibility with any pre-existing dormant rows that
     * predate the multi-channel work.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('channel', 20)->default('sms')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
