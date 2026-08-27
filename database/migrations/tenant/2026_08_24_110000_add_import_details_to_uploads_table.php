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
     * The `uploads` table was created (2026_08_19_090556) as a bare stub —
     * file_name/file_path/status only, with no way to record WHO ran an
     * import, WHAT kind of entity it targeted, or HOW MANY rows
     * succeeded/failed. That's exactly the "history of who imported what,
     * when, how many rows succeeded/failed" the zone/customer bulk-import
     * feature needs, so this fills the stub in rather than inventing a
     * parallel tracking table.
     */
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('file_path');
            // Cross-schema FK to the central public.users table — same
            // pattern as payment_verifications.verified_by (see that
            // migration's note): `users` lives on the landlord connection,
            // so this can't be a normal Blueprint ->constrained() call.
            $table->unsignedBigInteger('imported_by')->nullable()->after('type');
            $table->unsignedInteger('total_rows')->default(0)->after('imported_by');
            $table->unsignedInteger('succeeded_count')->default(0)->after('total_rows');
            $table->unsignedInteger('failed_count')->default(0)->after('succeeded_count');
            // Row-level failure detail: {row_number: reason}. Nullable —
            // absent entirely when every row succeeded.
            $table->jsonb('errors')->nullable()->after('failed_count');

            $table->index('type', 'idx_uploads_type');
            $table->index('imported_by', 'idx_uploads_imported_by');
        });

        // Cross-schema FK — same rationale/pattern as
        // payment_verifications.verified_by (see that migration's note):
        // tenant schemas run with a single-schema search_path, so `public`
        // is not implicitly searched and foreignId()->constrained() can't
        // reliably target a different-schema table from a Blueprint
        // closure.
        DB::statement('ALTER TABLE uploads ADD CONSTRAINT uploads_imported_by_foreign FOREIGN KEY (imported_by) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE uploads DROP CONSTRAINT uploads_imported_by_foreign');

        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex('idx_uploads_type');
            $table->dropIndex('idx_uploads_imported_by');
            $table->dropColumn(['type', 'imported_by', 'total_rows', 'succeeded_count', 'failed_count', 'errors']);
        });
    }
};
