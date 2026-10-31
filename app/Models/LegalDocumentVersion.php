<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a legal document (central schema) — see the migration's
 * docblock. Changed only through App\Services\LegalDocumentService.
 *
 * @property string $type
 * @property int $version
 * @property string $status
 * @property string $body_en
 * @property string|null $body_fr
 */
#[Connection('pgsql')]
#[Fillable(['type', 'version', 'status', 'body_en', 'body_fr', 'change_note', 'requires_acceptance', 'published_at', 'published_by', 'created_by'])]
class LegalDocumentVersion extends Model
{
    public const string TERMS = 'terms';

    public const string PRIVACY = 'privacy';

    public const string CONTRACT = 'contract';

    /** @var list<string> */
    public const array TYPES = [self::TERMS, self::PRIVACY, self::CONTRACT];

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    public const string RETIRED = 'retired';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'requires_acceptance' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The French text when there is one, else the English.
     */
    public function body(string $locale): string
    {
        return $locale === 'fr' && filled($this->body_fr) ? (string) $this->body_fr : $this->body_en;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<LegalAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }
}
