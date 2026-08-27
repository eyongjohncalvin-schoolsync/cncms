<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Central (public schema) index of which tenant(s) a user belongs to —
 * see the migration's docblock for why this exists. Kept in sync by
 * App\Models\TenantUser's booted() hooks; never written to directly
 * outside that sync path.
 */
#[Connection('pgsql')]
#[Fillable(['user_id', 'tenant_id', 'role'])]
class TenantUserIndex extends Model
{
    protected $table = 'tenant_user_index';
}
