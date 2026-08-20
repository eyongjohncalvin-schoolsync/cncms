<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tenant_id', 'role'])]
class TenantUser extends Model
{
    // Cross-schema relations — User and Tenant both pin themselves to the
    // central `pgsql` connection, so these resolve correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
