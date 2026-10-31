<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Terms, Privacy and the contract template, written and published by the
 * landlord (Landlord\LegalDocumentController), shown on the public site
 * (SiteController), and accepted by each company's super/admin
 * (EnsureLegalAccepted, LegalAcceptanceController, registration).
 *
 * Each document has at most one draft and one published version. Publishing
 * retires the old version — kept, because acceptances point at it — and
 * takes effect at once. A company accepts once for all of its admins.
 */
class LegalDocumentService
{
    public function current(string $type): ?LegalDocumentVersion
    {
        return Cache::rememberForever("legal:current:{$type}", fn () => LegalDocumentVersion::query()
            ->where('type', $type)
            ->where('status', LegalDocumentVersion::PUBLISHED)
            ->first());
    }

    public function draft(string $type): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('type', $type)->where('status', LegalDocumentVersion::DRAFT)->first();
    }

    /**
     * Markdown to safe HTML: raw HTML in the text is shown as text.
     */
    public static function toHtml(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * Creates the draft (numbered after the newest version) or updates it.
     *
     * @param  array{body_en: string, body_fr?: string|null, change_note?: string|null, requires_acceptance?: bool}  $data
     */
    public function saveDraft(string $type, array $data, User $user): LegalDocumentVersion
    {
        $fields = [
            'body_en' => $data['body_en'],
            'body_fr' => filled($data['body_fr'] ?? null) ? $data['body_fr'] : null,
            'change_note' => filled($data['change_note'] ?? null) ? $data['change_note'] : null,
            'requires_acceptance' => (bool) ($data['requires_acceptance'] ?? false),
        ];

        return DB::connection('pgsql')->transaction(function () use ($type, $fields, $user) {
            $draft = $this->draft($type);

            if ($draft) {
                $draft->update($fields);

                return $draft;
            }

            // Postgres refuses FOR UPDATE on an aggregate: lock the rows, then count.
            $next = (int) LegalDocumentVersion::query()->where('type', $type)->lockForUpdate()->pluck('version')->max() + 1;

            return LegalDocumentVersion::create([
                ...$fields,
                'type' => $type,
                'version' => $next,
                'status' => LegalDocumentVersion::DRAFT,
                'created_by' => $user->id,
            ]);
        });
    }

    public function publish(string $type, User $user): LegalDocumentVersion
    {
        $version = DB::connection('pgsql')->transaction(function () use ($type, $user) {
            $draft = LegalDocumentVersion::query()
                ->where('type', $type)
                ->where('status', LegalDocumentVersion::DRAFT)
                ->lockForUpdate()
                ->first() ?? throw new RuntimeException('There is no draft to publish.');

            LegalDocumentVersion::query()
                ->where('type', $type)
                ->where('status', LegalDocumentVersion::PUBLISHED)
                ->update(['status' => LegalDocumentVersion::RETIRED]);

            $draft->update([
                'status' => LegalDocumentVersion::PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id,
            ]);

            return $draft;
        });

        Cache::forget("legal:current:{$type}");

        return $version;
    }

    public function discardDraft(string $type): bool
    {
        return (bool) $this->draft($type)?->delete();
    }

    /**
     * Records that $user accepted the current version of each document for
     * $tenantId. Documents with no published version are skipped.
     *
     * @param  list<string>  $types
     */
    public function recordAcceptance(User $user, ?string $tenantId, array $types, Request $request): void
    {
        foreach ($types as $type) {
            $version = $this->current($type);

            if ($version === null) {
                continue;
            }

            LegalAcceptance::create([
                'legal_document_version_id' => $version->id,
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);

            if ($tenantId !== null) {
                Cache::forever($this->acceptedKey($tenantId, $version->id), true);
            }
        }
    }

    /**
     * The Terms version the company still has to accept, or null. Only a
     * published version marked "must accept" asks anything of anyone.
     */
    public function termsAwaitingAcceptance(string $tenantId): ?LegalDocumentVersion
    {
        $terms = $this->current(LegalDocumentVersion::TERMS);

        if ($terms === null || ! $terms->requires_acceptance) {
            return null;
        }

        $accepted = Cache::get($this->acceptedKey($tenantId, $terms->id));

        if ($accepted === null) {
            $accepted = LegalAcceptance::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_document_version_id', $terms->id)
                ->exists();

            if ($accepted) {
                Cache::forever($this->acceptedKey($tenantId, $terms->id), true);
            }
        }

        return $accepted ? null : $terms;
    }

    private function acceptedKey(string $tenantId, int $versionId): string
    {
        return "legal:accepted:{$tenantId}:{$versionId}";
    }
}
