<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which zones each field agent (recovery agent) covers. Management decides:
 * one agent may cover one zone, several, or all of them (a startup), and a
 * busy zone may have helpers. Each zone has at most one `responsible`
 * collector — the name printed on its bills — enforced by a partial unique
 * index, so not even a bug can give a zone two.
 *
 * An agent's covered zones are always in the agent's own branch (a branch is
 * a closed world): both composite foreign keys share zone_agents.branch_id.
 * agents.zone_id stays as the agent's home zone — the anchor of the agent's
 * branch, and what app builds before 1.0.1 show as "my zone".
 *
 * Existing data: every agent covers its current zone; in each zone the
 * longest-serving active agent becomes responsible, any others helpers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->unique(['id', 'branch_id'], 'agents_id_branch_unique');
        });

        Schema::create('zone_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id');
            $table->foreignId('agent_id');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('role', 12);
            // Central users.id of whoever assigned it (cross-schema, no FK).
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['zone_id', 'agent_id']);
            $table->index('agent_id');
        });

        DB::statement("ALTER TABLE zone_agents ADD CONSTRAINT zone_agents_role_check CHECK (role IN ('responsible', 'helper'))");
        DB::statement('ALTER TABLE zone_agents ADD CONSTRAINT zone_agents_zone_branch_foreign FOREIGN KEY (zone_id, branch_id) REFERENCES zones (id, branch_id) ON UPDATE CASCADE ON DELETE CASCADE');
        DB::statement('ALTER TABLE zone_agents ADD CONSTRAINT zone_agents_agent_branch_foreign FOREIGN KEY (agent_id, branch_id) REFERENCES agents (id, branch_id) ON UPDATE CASCADE ON DELETE CASCADE');
        DB::statement("CREATE UNIQUE INDEX zone_agents_one_responsible ON zone_agents (zone_id) WHERE role = 'responsible'");

        DB::statement(<<<'SQL'
            INSERT INTO zone_agents (zone_id, agent_id, branch_id, role, created_at, updated_at)
            SELECT ranked.zone_id, ranked.id, ranked.branch_id,
                   CASE WHEN ranked.status = 'active' AND ranked.rank = 1 THEN 'responsible' ELSE 'helper' END,
                   now(), now()
            FROM (
                SELECT a.id, a.zone_id, a.branch_id, a.status,
                       row_number() OVER (PARTITION BY a.zone_id ORDER BY (a.status = 'active') DESC, a.id) AS rank
                FROM agents a
                WHERE a.zone_id IS NOT NULL
            ) AS ranked
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_agents');

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropUnique('agents_id_branch_unique');
        });
    }
};
