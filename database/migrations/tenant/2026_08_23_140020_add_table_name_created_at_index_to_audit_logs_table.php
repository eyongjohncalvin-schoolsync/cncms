<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a composite (table_name, created_at DESC) index. audit_logs is an
 * unboundedly-growing table (23,542+ rows and counting) queried/paginated
 * by table_name filtered and ordered by created_at desc; the previous
 * single-column indexes on table_name and created_at separately forced a
 * Filter step on table_name after an Index Cond on created_at alone.
 *
 * Laravel's index() helper doesn't support per-column sort direction, so
 * this is created with raw DDL (Postgres supports DESC in index
 * definitions).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_audit_logs_table_name_created_at ON audit_logs (table_name, created_at DESC)');
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_table_name_created_at');
        });
    }
};
