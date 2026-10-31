<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's online signature of one version of the service contract
 * (central schema, append-only) — written only by
 * App\Services\ContractService::sign(). `body` is the exact text signed,
 * blanks filled in; `pdf_path` is on the local (private) disk.
 */
#[Connection('pgsql')]
#[Fillable(['legal_document_version_id', 'tenant_id', 'user_id', 'signer_name', 'signer_title', 'company_name', 'locale', 'body', 'body_sha256', 'pdf_path', 'signed_at', 'ip_address', 'user_agent'])]
class ContractSignature extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
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
