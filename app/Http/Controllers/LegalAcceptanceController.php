<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LegalDocumentVersion;
use App\Services\LegalDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a company's super/admin sees when the landlord has published
 * Terms marked "must accept" (EnsureLegalAccepted). One acceptance covers
 * the whole company.
 */
class LegalAcceptanceController extends Controller
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function show(): Response|RedirectResponse
    {
        $terms = $this->legal->termsAwaitingAcceptance((string) tenant('id'));

        if ($terms === null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Legal/Accept', [
            'version' => $terms->version,
            'published_at' => $terms->published_at?->toIso8601String(),
            'change_note' => $terms->change_note,
            'body_html' => LegalDocumentService::toHtml($terms->body(app()->getLocale())),
            'can_accept' => $this->canAccept(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canAccept(), 403);

        $request->validate(['accept' => ['accepted']], ['accept.accepted' => 'Tick the box to accept the Terms.']);

        if ($this->legal->termsAwaitingAcceptance((string) tenant('id')) !== null) {
            $this->legal->recordAcceptance($request->user(), (string) tenant('id'), [LegalDocumentVersion::TERMS], $request);
        }

        return redirect()->intended(route('dashboard'))->with('success', 'Thank you. The new Terms are accepted for your company.');
    }

    private function canAccept(): bool
    {
        $context = app(TenantContext::class);

        return $context->isSuper() || $context->is('admin');
    }
}
