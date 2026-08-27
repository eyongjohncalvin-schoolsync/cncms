<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * Detects a Postgres unique_violation (SQLSTATE 23505) wrapped inside a
 * Laravel QueryException — shared by every caller that needs to translate a
 * partial-unique-index collision (idx_command_runs_period_inflight; see that
 * migration's doc comment) into a friendly error instead of letting a raw
 * QueryException bubble up. Used by both
 * App\Services\ManuscriptGenerationBatchService::dispatch() (the original
 * web/scheduled path) and App\Console\Commands\ManuscriptCalculate (the CLI
 * path, once it started inserting its own 'queued' command_runs row ahead of
 * its synchronous computation — 2026-08-27) so the same index protects both
 * entry points identically. Checks the underlying PDO exception's SQLSTATE
 * rather than matching on message text/constraint name, which is fragile
 * across Postgres versions/locales.
 */
trait DetectsUniqueViolation
{
    protected function isUniqueViolation(QueryException $e): bool
    {
        $previous = $e->getPrevious();

        $sqlState = $previous instanceof PDOException ? $previous->getCode() : $e->getCode();

        return $sqlState === '23505';
    }
}
