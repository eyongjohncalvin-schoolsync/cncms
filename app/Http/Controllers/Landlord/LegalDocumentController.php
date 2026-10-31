<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SiteController;
use App\Http\Requests\SaveLegalDraftRequest;
use App\Models\LandlordActivity;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\Tenant;
use App\Services\LegalDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Landlord → Legal documents: write Terms, Privacy and the contract template
 * in English and French, publish new versions instantly, see the history
 * and who accepted what (App\Services\LegalDocumentService). Gated by the
 * `landlord` middleware in routes/web/landlord.php.
 */
class LegalDocumentController extends Controller
{
    /** @var array<string, string> */
    public const array LABELS = [
        LegalDocumentVersion::TERMS => 'Terms of service',
        LegalDocumentVersion::PRIVACY => 'Privacy policy',
        LegalDocumentVersion::CONTRACT => 'Service contract template',
    ];

    public function __construct(private readonly LegalDocumentService $legal) {}

    public function index(): Response
    {
        $approvedCompanies = $this->approvedCompanies();

        return Inertia::render('Landlord/Legal/Index', [
            'documents' => collect(LegalDocumentVersion::TYPES)->map(function (string $type) use ($approvedCompanies) {
                $current = $this->legal->current($type);

                return [
                    'type' => $type,
                    'label' => self::LABELS[$type],
                    'version' => $current?->version,
                    'published_at' => $current?->published_at?->toIso8601String(),
                    'requires_acceptance' => (bool) $current?->requires_acceptance,
                    'has_draft' => $this->legal->draft($type) !== null,
                    'accepted' => $current && $this->tracksCompanies($current) ? $this->acceptedCompanyIds($current)->intersect($approvedCompanies->keys())->count() : null,
                    'companies' => $approvedCompanies->count(),
                ];
            })->values(),
        ]);
    }

    public function edit(string $type): Response
    {
        $current = $this->legal->current($type);
        $draft = $this->legal->draft($type);
        $companies = $this->approvedCompanies();
        $accepted = $current ? $this->acceptedCompanyIds($current) : collect();

        return Inertia::render('Landlord/Legal/Edit', [
            'type' => $type,
            'label' => self::LABELS[$type],
            'current' => $current ? $this->shape($current) : null,
            'draft' => $draft ? $this->shape($draft) : null,
            'history' => LegalDocumentVersion::query()
                ->where('type', $type)
                ->where('status', '!=', LegalDocumentVersion::DRAFT)
                ->with('publisher:id,name')
                ->withCount('acceptances')
                ->orderByDesc('version')
                ->get()
                ->map(fn (LegalDocumentVersion $version) => [
                    'version' => $version->version,
                    'status' => $version->status,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'published_by' => $version->publisher?->name,
                    'change_note' => $version->change_note,
                    'requires_acceptance' => $version->requires_acceptance,
                    'acceptances' => $version->acceptances_count,
                ]),
            'acceptances' => $current ? LegalAcceptance::query()
                ->where('legal_document_version_id', $current->id)
                ->with('user:id,name')
                ->latest('accepted_at')
                ->limit(100)
                ->get()
                ->map(fn (LegalAcceptance $acceptance) => [
                    'company' => $companies->get($acceptance->tenant_id) ?? $acceptance->tenant_id,
                    'user' => $acceptance->user?->name,
                    'accepted_at' => $acceptance->accepted_at->toIso8601String(),
                    'ip_address' => $acceptance->ip_address,
                ]) : [],
            'waiting' => $current && $this->tracksCompanies($current)
                ? $companies->reject(fn (string $name, string $id) => $accepted->contains($id))->values()
                : [],
        ]);
    }

    public function saveDraft(SaveLegalDraftRequest $request, string $type): RedirectResponse
    {
        $draft = $this->legal->saveDraft($type, [
            ...$request->validated(),
            'requires_acceptance' => $type === LegalDocumentVersion::TERMS && $request->boolean('requires_acceptance'),
        ], $request->user());

        LandlordActivity::record('legal.draft_saved', 'Saved a draft of '.self::LABELS[$type]." (version {$draft->version})", 'legal', $type);

        return back()->with('success', "Draft of version {$draft->version} saved. Preview it, then publish when ready.");
    }

    public function publish(Request $request, string $type): RedirectResponse
    {
        try {
            $version = $this->legal->publish($type, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        LandlordActivity::record(
            'legal.published',
            'Published '.self::LABELS[$type]." version {$version->version}",
            'legal',
            $type,
            ['version' => $version->version, 'requires_acceptance' => $version->requires_acceptance, 'change_note' => $version->change_note],
        );

        $suffix = $version->requires_acceptance ? ' Company admins are asked to accept it at their next page view.' : '';

        return back()->with('success', self::LABELS[$type]." version {$version->version} is live.{$suffix}");
    }

    public function discardDraft(string $type): RedirectResponse
    {
        if ($this->legal->discardDraft($type)) {
            LandlordActivity::record('legal.draft_discarded', 'Discarded the draft of '.self::LABELS[$type], 'legal', $type);
        }

        return back()->with('success', 'Draft discarded.');
    }

    /**
     * The draft or any past version, as it looks (or looked) on the site.
     */
    public function preview(Request $request, string $type, string $version): HttpResponse
    {
        $document = LegalDocumentVersion::query()
            ->where('type', $type)
            ->when(
                $version === 'draft',
                fn ($query) => $query->where('status', LegalDocumentVersion::DRAFT),
                fn ($query) => $query->where('version', (int) $version),
            )
            ->firstOrFail();

        $locale = $request->query('locale') === 'fr' ? 'fr' : 'en';
        $previous = App::getLocale();

        // setLocale() also changes the app's default language: render now,
        // then put it back.
        try {
            App::setLocale($locale);

            return response(app(SiteController::class)->legalView($type, $document->version, $document->published_at, $document->body($locale))->render());
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * @return array{version: int, status: string, body_en: string, body_fr: string, change_note: string, requires_acceptance: bool, published_at: string|null}
     */
    private function shape(LegalDocumentVersion $version): array
    {
        return [
            'version' => $version->version,
            'status' => $version->status,
            'body_en' => $version->body_en,
            'body_fr' => (string) $version->body_fr,
            'change_note' => (string) $version->change_note,
            'requires_acceptance' => $version->requires_acceptance,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    /**
     * Approved companies, id => name, oldest first.
     *
     * @return Collection<string, string>
     */
    private function approvedCompanies(): Collection
    {
        return Tenant::query()
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->isApproved())
            ->mapWithKeys(fn (Tenant $tenant) => [$tenant->id => (string) $tenant->name]);
    }

    /**
     * Whether every company is expected to accept this version: "must
     * accept" Terms, and every contract (signed in Settings → Contract,
     * App\Services\ContractService, which records the acceptance too).
     */
    private function tracksCompanies(LegalDocumentVersion $version): bool
    {
        return $version->requires_acceptance || $version->type === LegalDocumentVersion::CONTRACT;
    }

    /**
     * @return Collection<int, string>
     */
    private function acceptedCompanyIds(LegalDocumentVersion $version): Collection
    {
        return LegalAcceptance::query()
            ->where('legal_document_version_id', $version->id)
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id');
    }
}
