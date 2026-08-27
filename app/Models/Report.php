<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Not an Eloquent model — there is no `reports` table (the whole feature is
 * computed on demand from existing tables, see App\Services\ReportService's
 * class doc). This is a lightweight authorization "subject" only: every
 * other Policy in this codebase gates a real Eloquent model
 * ($this->authorize('ability', SomeModel::class)), and the /reports feature
 * needs the exact same `$this->authorize()` ergonomics without inventing a
 * table just to have something to point a Policy at. Registered explicitly
 * via Gate::policy(Report::class, ReportPolicy::class) in
 * App\Providers\AppServiceProvider::boot() rather than relying on Laravel's
 * naming-convention auto-discovery (which normally infers *Policy names for
 * classes under App\Models\*) — explicit here removes any doubt for a class
 * that isn't a real model.
 */
final class Report {}
