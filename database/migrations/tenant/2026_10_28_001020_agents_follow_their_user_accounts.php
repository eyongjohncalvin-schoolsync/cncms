<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A field agent is a user (owner, 2026-10-28): the agent record is created
 * with the user's account (role `agent`) and the Agents page only manages
 * zone coverage. So an agent record may exist before any zone is given —
 * zone_id nullable, its branch then the user's — and the old form-only
 * columns get defaults.
 *
 * Every agent-role user without an agent record gets one now (the Users
 * page used to show "No agent profile — set up" for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE agents ALTER COLUMN zone_id DROP NOT NULL');
        DB::statement("ALTER TABLE agents ALTER COLUMN location SET DEFAULT ''");
        DB::statement("ALTER TABLE agents ALTER COLUMN phone SET DEFAULT ''");
        DB::statement('ALTER TABLE agents ALTER COLUMN salary SET DEFAULT 0');

        $headquartersId = DB::table('branches')->where('is_headquarters', true)->value('id') ?? DB::table('branches')->min('id');

        if ($headquartersId === null) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO agents (user_id, branch_id, name, email, status, created_at, updated_at)
            SELECT tu.user_id, COALESCE(tu.branch_id, ?), left(u.name, 50), left(u.email, 50),
                   CASE WHEN u.status = 'active' THEN 'active' ELSE 'inactive' END, now(), now()
            FROM tenant_users tu
            JOIN public.users u ON u.id = tu.user_id
            WHERE tu.role = 'agent'
              AND NOT EXISTS (SELECT 1 FROM agents a WHERE a.user_id = tu.user_id)
            SQL, [$headquartersId]);
    }

    public function down(): void
    {
        // Agents without a home zone can't keep NOT NULL; only the defaults go back.
        DB::statement('ALTER TABLE agents ALTER COLUMN location DROP DEFAULT');
        DB::statement('ALTER TABLE agents ALTER COLUMN phone DROP DEFAULT');
        DB::statement('ALTER TABLE agents ALTER COLUMN salary DROP DEFAULT');
    }
};
