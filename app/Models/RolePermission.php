<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (role, permission) grant row in the RBAC v2 pivot. Composite primary
 * key (role_id, permission), no surrogate id, no timestamps — a grant
 * either exists or it doesn't.
 */
#[Fillable(['role_id', 'permission'])]
class RolePermission extends Model
{
    protected $table = 'role_permissions';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'role_id';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
