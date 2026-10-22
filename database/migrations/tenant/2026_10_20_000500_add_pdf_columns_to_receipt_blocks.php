<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A printed receipt book (up to 100 leaves, a QR code each) takes longer
     * to render than a web request may run, so it is built by a queued job
     * (App\Jobs\GenerateReceiptBookPdfJob) and stored; the Print button then
     * downloads the stored file. pdf_status: null (never built) → pending →
     * ready | failed.
     */
    public function up(): void
    {
        Schema::table('receipt_blocks', function (Blueprint $table) {
            $table->string('pdf_status', 10)->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_disk', 40)->nullable();
            $table->string('pdf_error', 500)->nullable();
            $table->timestampTz('pdf_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_blocks', function (Blueprint $table) {
            $table->dropColumn(['pdf_status', 'pdf_path', 'pdf_disk', 'pdf_error', 'pdf_generated_at']);
        });
    }
};
