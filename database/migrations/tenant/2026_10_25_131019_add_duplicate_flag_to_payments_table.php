<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second subscription payment for the same customer in the same month is
 * recorded (the money exists) but flagged: which earlier payment it may
 * repeat, and why the collector said it is fine ("paying ahead", "paying old
 * debt", …, or "unconfirmed" when an offline phone couldn't know). A flagged
 * payment is never verified on the spot — someone else decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('duplicate_of_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('duplicate_reason', 20)->nullable();
            $table->string('duplicate_note', 255)->nullable();
            $table->index('duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropColumn(['duplicate_reason', 'duplicate_note']);
        });
    }
};
