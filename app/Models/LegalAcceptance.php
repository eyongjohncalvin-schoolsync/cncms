<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that a person accepted one version of a legal document for a
 * company (central schema, append-only) — written only by
 * App\Services\LegalDocumentService::recordAcceptance().
 */
#[Connection('pgsql')]
#[Fillable(['legal_document_version_id', 'user_id', 'tenant_id', 'accepted_at', 'ip_address', 'user_agent'])]
class LegalAcceptance extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LegalDocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
