<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContractSignature;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Services\ContractService;
use App\Services\LegalDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Contract: the company's super/admin reads the OneTech service
 * contract with their company's details filled in, signs it online, and
 * downloads the signed PDFs (App\Services\ContractService). Allowed while
 * the demo is read-only, since signing is how a company gets activated.
 */
class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contracts) {}

    public function show(Request $request): Response
    {
        $this->authorizeAdmin();

        $tenant = $this->tenant();
        $contract = $this->contracts->current();
        $awaiting = $this->contracts->awaitingSignature($tenant->id);
        $locale = app()->getLocale();

        return Inertia::render('Settings/Contract', [
            'contract' => $contract ? [
                'version' => $contract->version,
                'published_at' => $contract->published_at?->toIso8601String(),
                'change_note' => $contract->change_note,
                'body_html' => LegalDocumentService::toHtml($this->contracts->fill($contract, $tenant, (string) $request->user()->name, $locale)),
            ] : null,
            'needs_signature' => $awaiting !== null,
            'default_name' => (string) $request->user()->name,
            'signatures' => ContractSignature::query()
                ->where('tenant_id', $tenant->id)
                ->with('documentVersion:id,version')
                ->latest('signed_at')
                ->get()
                ->map(fn (ContractSignature $signature) => [
                    'id' => $signature->id,
                    'version' => $signature->documentVersion->version,
                    'signer_name' => $signature->signer_name,
                    'signer_title' => $signature->signer_title,
                    'signed_at' => $signature->signed_at->toIso8601String(),
                ]),
        ]);
    }

    public function sign(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'min:3', 'max:120'],
            'signer_title' => ['nullable', 'string', 'max:120'],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Tick the box to confirm you agree and may sign for your company.',
        ]);

        $tenant = $this->tenant();

        if ($this->contracts->awaitingSignature($tenant->id) === null) {
            return back()->with('error', 'Your company has already signed this version.');
        }

        try {
            $signature = $this->contracts->sign($request->user(), $tenant, trim($validated['signer_name']), filled($validated['signer_title'] ?? null) ? trim($validated['signer_title']) : null, app()->getLocale(), $request);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        LandlordActivity::record(
            'contract.signed',
            "{$signature->company_name} signed the service contract (version {$this->contracts->current()?->version}) — {$signature->signer_name}",
            'tenant',
            $tenant->id,
            ['contract_signature_id' => $signature->id],
        );

        return back()->with('success', "Signed. Thank you, {$signature->signer_name}. You can download your copy below.");
    }

    public function pdf(ContractSignature $signature): StreamedResponse
    {
        $this->authorizeAdmin();
        abort_unless($signature->tenant_id === $this->tenant()->id, 404);

        return Storage::disk(ContractService::DISK)->download($signature->pdf_path, basename($signature->pdf_path));
    }

    private function authorizeAdmin(): void
    {
        $context = app(TenantContext::class);

        abort_unless($context->isSuper() || $context->is('admin'), 403);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
