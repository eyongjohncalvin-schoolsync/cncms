<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

// Central model, same reasoning as User: personal_access_tokens lives in the
// central `public` schema (see the Sanctum migration under
// database/migrations, which is NOT a tenant migration). Pinning the
// connection means token lookups resolve correctly on every request
// regardless of which tenant schema happens to be active on the default
// connection when Sanctum's guard queries this table — auth:sanctum runs
// before ResolveTenant on every /api/v1/* route, so in a normal
// per-request boot this would already work by luck (the default
// connection hasn't been switched to a tenant yet), but pinning it
// explicitly makes that correctness guaranteed rather than incidental
// (e.g. across requests reusing the same booted application, as happens
// in feature tests).
#[Connection('pgsql')]
class PersonalAccessToken extends SanctumPersonalAccessToken {}
