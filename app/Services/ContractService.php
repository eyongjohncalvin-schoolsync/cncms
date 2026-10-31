<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\LegalDocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTimezone;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The OneTech ↔ operator service contract, signed online. The landlord
 * writes and publishes the template (Landlord → Legal documents, type
 * `contract`) with blanks such as {company_name}; a company's super/admin
 * reads it with their company's details filled in, types their name and
 * signs (ContractController). The signature keeps the exact text signed
 * and a PDF copy, and counts as the company's acceptance of that version.
 * A newly published version asks every company to sign again.
 *
 * Must run with the company's tenancy initialized (company details).
 */
class ContractService
{
    /** The blanks a template may use, as shown on the landlord's editor. */
    public const array BLANKS = ['company_name', 'company_location', 'company_rccm', 'company_niu', 'owner_name', 'demo_start', 'demo_end', 'today'];

    public const string DISK = 'local';

    public function __construct(private readonly LegalDocumentService $legal) {}

    public function current(): ?LegalDocumentVersion
    {
        return $this->legal->current(LegalDocumentVersion::CONTRACT);
    }

    /**
     * The published contract version the company has not signed yet, or null.
     */
    public function awaitingSignature(string $tenantId): ?LegalDocumentVersion
    {
        $contract = $this->current();

        if ($contract === null) {
            return null;
        }

        $signed = Cache::get($this->signedKey($tenantId, $contract->id));

        if ($signed === null) {
            $signed = ContractSignature::query()->where('tenant_id', $tenantId)->where('legal_document_version_id', $contract->id)->exists();

            if ($signed) {
                Cache::forever($this->signedKey($tenantId, $contract->id), true);
            }
        }

        return $signed ? null : $contract;
    }

    /**
     * The template's text with this company's details filled in.
     */
    public function fill(LegalDocumentVersion $contract, Tenant $tenant, string $ownerName, string $locale): string
    {
        $company = Company::cached();
        $date = fn (?string $value): string => $value ? CarbonImmutable::parse($value)->locale($locale)->translatedFormat('j F Y') : '—';
        $values = [
            'company_name' => $company?->name ?: (string) $tenant->name,
            'company_location' => $company?->location ?: '—',
            'company_rccm' => $company?->rccm_number ?: '—',
            'company_niu' => $company?->niu ?: '—',
            'owner_name' => $ownerName !== '' ? $ownerName : '—',
            'demo_start' => $date($tenant->demo_starts_on ?? null),
            'demo_end' => $date($tenant->demo_ends_on ?? null),
            'today' => $date(BusinessTimezone::today()),
        ];

        return strtr($contract->body($locale), collect($values)->mapWithKeys(fn (string $value, string $blank) => ['{'.$blank.'}' => $value])->all());
    }

    /**
     * Signs the current contract for the company: keeps the filled-in text,
     * renders and stores the PDF, and records the acceptance.
     */
    public function sign(User $user, Tenant $tenant, string $signerName, ?string $signerTitle, string $locale, Request $request): ContractSignature
    {
        $contract = $this->current() ?? throw new RuntimeException('There is no contract to sign yet.');
        $body = $this->fill($contract, $tenant, $signerName, $locale);
        $signedAt = now();
        $companyName = Company::cached()?->name ?: (string) $tenant->name;
        $path = "contracts/{$tenant->id}/contract-v{$contract->version}-{$signedAt->format('Ymd-His')}.pdf";

        $signature = new ContractSignature([
            'legal_document_version_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'signer_name' => $signerName,
            'signer_title' => $signerTitle,
            'company_name' => $companyName,
            'locale' => $locale,
            'body' => $body,
            'body_sha256' => hash('sha256', $body),
            'pdf_path' => $path,
            'signed_at' => $signedAt,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);

        $pdf = Pdf::loadView('pdf.contract', ['signature' => $signature, 'contract' => $contract, 'bodyHtml' => LegalDocumentService::toHtml($body)])
            ->setPaper('a4')
            ->output();

        Storage::disk(self::DISK)->put($path, $pdf);

        DB::connection('pgsql')->transaction(function () use ($signature, $user, $tenant, $request) {
            $signature->save();
            $this->legal->recordAcceptance($user, $tenant->id, [LegalDocumentVersion::CONTRACT], $request);
        });

        Cache::forever($this->signedKey($tenant->id, $contract->id), true);

        return $signature;
    }

    private function signedKey(string $tenantId, int $versionId): string
    {
        return "contract:signed:{$tenantId}:{$versionId}";
    }
}
