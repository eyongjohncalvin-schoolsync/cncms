<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two GIN indexes on audit_logs.new_values / old_values serve only
 * jsonb containment (@>, ?), which no query uses — every read extracts a
 * field as text (`->>`), which they can't serve. Each audited write (every
 * payment, bill, receipt leaf…) still paid to maintain both (query audit,
 * 2026-10-27). down() puts them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('drop index if exists idx_audit_jsonb_new');
        DB::statement('drop index if exists idx_audit_jsonb_old');
    }

    public function down(): void
    {
        DB::statement('create index if not exists idx_audit_jsonb_new on audit_logs using gin (new_values)');
        DB::statement('create index if not exists idx_audit_jsonb_old on audit_logs using gin (old_values)');
    }
};
