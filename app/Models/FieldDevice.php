<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collector's phone, registered so it can issue receipts while offline
 * (docs/plans/cash-controls.md, wave 2). `secret` is the HMAC key the phone
 * signs each receipt's security code with — handed to the phone exactly
 * once at registration, encrypted at rest, never serialised, never audited
 * (AuditableObserver excludes it). A revoked device's codes are refused.
 */
#[Fillable(['user_id', 'branch_id', 'name', 'secret', 'registered_at', 'last_seen_at', 'revoked_at', 'revoked_by'])]
#[Hidden(['secret'])]
#[RouteKey('uuid')]
class FieldDevice extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // Cross-schema — User is pinned to the central connection.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ReceiptBlock::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Set by the creating service (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return null;
    }
}
