<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Message;
use App\Services\BillNotificationService;
use App\Services\ManuscriptGenerationBatchService;
use App\Services\ManuscriptPreRunReviewService;
use App\Services\ManuscriptService;
use App\Services\ZoneService;
use App\Support\ResolvesCommandRunBatchProgress;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web (session-auth, Inertia) counterpart to Api\ManuscriptController — same
 * ManuscriptService, rendered as Inertia pages instead of JSON. See
 * web-admin-spec.md section 3.8.
 */
class ManuscriptController extends Controller
{
    use ResolvesCommandRunBatchProgress;

    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly ZoneService $zones,
        private readonly BillNotificationService $billNotifications,
        private readonly ManuscriptGenerationBatchService $batches,
        private readonly ManuscriptPreRunReviewService $preRunReview,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Manuscript::class);

        $filters = $request->only(['period', 'zone_uuid', 'status']);
        $period = (string) ($filters['period'] ?? now()->format('Y-m'));

        $paginated = $this->manuscripts->list($filters, 25);

        $paginated->through(fn (Manuscript $manuscript): array => [
            'customer_uuid' => $manuscript->customer->uuid,
            'customer_name' => $manuscript->customer->name,
            'customer_code' => substr($manuscript->customer->uuid, 0, 8),
            'phone' => $manuscript->customer->phone,
            'zone_name' => $manuscript->customer->zone?->name,
            'level' => $manuscript->customer->level,
            'bill' => $manuscript->bill,
            'total_arrears' => $manuscript->total_arrears,
            'credit' => $manuscript->credit,
            'total_bill' => $manuscript->total_bill,
            'payment_expiration' => $manuscript->payment_expiration?->toDateString(),
            'prepaid_months_remaining' => (int) $manuscript->prepaid_months_remaining,
            'prepaid_rate' => $manuscript->prepaid_rate,
            'period' => $manuscript->period,
            'status' => $manuscript->customer->status,
            // Built from THIS row's own manuscript (not necessarily the
            // customer's latest) so the wa.me link matches exactly what's
            // displayed on this row — see BillNotificationService::waLink()'s
            // optional $manuscript parameter.
            'wa_link' => $this->billNotifications->waLink($manuscript->customer, $manuscript),
        ]);

        return Inertia::render('Manuscripts/Index', [
            'period' => $period,
            'filters' => $filters,
            'manuscripts' => $this->paginatorProps($paginated),
            'summary' => $this->manuscripts->summary($filters),
            'zones' => $this->zones->all(),
        ]);
    }

    /**
     * Mirrors Api\ManuscriptController::export() — same PDF, streamed
     * directly instead of behind a JSON envelope.
     */
    public function export(Request $request): Response
    {
        $this->authorize('export', Manuscript::class);

        $filters = $request->only(['period', 'zone_uuid', 'status']);

        $data = $this->manuscripts->exportData($filters);

        return Pdf::loadView('pdf.manuscript', $data)
            ->setPaper('a4', 'landscape')
            ->stream('manuscript-'.$data['period'].'.pdf');
    }

    /**
     * "Run Manuscript Calculation" (web-admin-spec.md section 3.8, gated
     * admin/super only via ManuscriptPolicy::calculate()) — the manual
     * trigger. Per task-scheduler.md section 4.1, this dispatches the same
     * chunked, queued Bus::batch() mechanism the scheduled path uses
     * (thousands-of-customers robustness applies to a manually-triggered
     * run exactly as much as a scheduled one), rather than running
     * manuscript:calculate synchronously inline in this request.
     *
     * **Stage 3 (task-scheduler.md's 2026-08-27 "manual/scheduled
     * convergence" addendum) flips this to autoPublish=false** — manual and
     * scheduled runs now land on the IDENTICAL pending_review -> Publish
     * gate; this button no longer commits immediately the instant its batch
     * finishes computing. What used to be "only the review gate stays
     * scheduled-path-only" is no longer true: only the EXECUTION mechanism
     * (chunking, Bus::batch()) was ever meant to be shared unconditionally;
     * per the completed architecture deliberation, the review gate itself
     * converges too, since "the reviewer already stood there and clicked
     * Run" is not actually a substitute for "the reviewer looked at the
     * computed numbers" — those are two different acts. See
     * App\Services\ManuscriptGenerationBatchService.
     *
     * Redirects to the new one-click review screen
     * (Manuscripts/RunReview.tsx, runReview() below) keyed on the
     * CommandRun this dispatch() just created, rather than back to the
     * Manuscripts index — the admin is standing there waiting for a run
     * they just triggered, which is a different need from Settings >
     * Command Runs' "review a run from hours ago" surface.
     *
     * `confirmed_rerun` (task-scheduler.md's 2026-08-27 "already safely
     * runnable" guard addendum): App\Services\ManuscriptRerunGuard refuses
     * dispatch() when a PUBLISHED run already exists for $period unless this
     * is explicitly true — validated as a real boolean below (`sometimes`,
     * `boolean`; a plain truthy string like "yes" is rejected) rather than
     * accepted as loose truthiness, since this flag consciously bypasses a
     * safety check. Distinct from — and stacked on top of —
     * idx_command_runs_period_inflight's existing simultaneous-run lock,
     * which this endpoint was already subject to and remains subject to.
     */
    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('calculate', Manuscript::class);

        // 2026-08-28 correction (business-rules.md section 2): triggered
        // near month-end, this run governs the UPCOMING month, not the one
        // it's clicked in — see ManuscriptCalculate's identical comment.
        $period = (string) $request->input('period', now()->addMonthNoOverflow()->format('Y-m'));

        // A bad/mistyped period reaching this endpoint previously ran and
        // auto-published billing for the ENTIRE tenant against that literal
        // string (dispatch() with no $customerIds resolves every customer) —
        // a stray future period (e.g. "2031-02") fabricated real, non-frozen
        // manuscript rows for every customer, corrupting BillNotificationService's
        // "latest manuscript" for all of them at once. Mirrors
        // StoreArrearsAdjustmentRequest's target_period validation (format +
        // not-in-the-future) since the same invariant applies here — except
        // "future" now means "beyond the upcoming month", since generating
        // next month's bill in advance is the NORMAL case, not a mistake.
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > now()->addMonthNoOverflow()->format('Y-m')) {
            return redirect()->back()->with('error', "Invalid period \"{$period}\" — expected format YYYY-MM, and it cannot be beyond next month.");
        }

        $validated = $request->validate([
            'confirmed_rerun' => ['sometimes', 'boolean'],
        ]);

        $run = $this->batches->dispatch(
            $period,
            scheduledTask: null,
            autoPublish: false,
            actingUserId: Auth::id(),
            override: (bool) ($validated['confirmed_rerun'] ?? false),
        );

        return redirect()->route('manuscripts.runs.show', $run)
            ->with('success', "Manuscript calculation for {$period} started — review and publish it below once it finishes computing.");
    }

    /**
     * The new, lightweight "just-triggered, watch it compute, then review
     * and publish" screen reachable in one click from Manuscripts/Index.tsx
     * (task-scheduler.md's 2026-08-27 stage 3 addendum) — deliberately NOT
     * a redirect to Settings > Command Runs, which is built for reviewing a
     * run from hours ago, not standing there watching one an admin just
     * kicked off.
     *
     * Renders the identical `computed_result_summary`/`batch_progress` shape
     * SettingsCommandRunController::index() already produces per row (same
     * field names, so the frontend's extracted ManuscriptRunSummary
     * component and its batch-progress polling logic work unchanged here) —
     * just for the single $run this page is keyed on instead of a
     * paginated list.
     *
     * Gated to the same ability as triggering the run at all
     * (ManuscriptPolicy::calculate()) rather than CommandRunPolicy::viewAny()
     * — this is that same action's own follow-through screen, not a general
     * command-run browsing surface.
     */
    public function runReview(CommandRun $run): InertiaResponse
    {
        $this->authorize('calculate', Manuscript::class);

        abort_unless($run->command === 'manuscript:calculate', 404);

        $batchProgressById = $this->batchProgress($run->batch_id ? [$run->batch_id] : []);

        return Inertia::render('Manuscripts/RunReview', [
            'run' => [
                'uuid' => $run->uuid,
                'command' => $run->command,
                'period' => $run->period,
                'ran_at' => $run->ran_at,
                'metadata' => $run->metadata,
                'status' => $run->status,
                'computed_result_summary' => $run->computed_result['summary'] ?? null,
                'batch_progress' => $batchProgressById[$run->batch_id] ?? null,
                'published_at' => $run->published_at,
            ],
            'canPublish' => Auth::user()?->can('publish', CommandRun::class) ?? false,
        ]);
    }

    /**
     * The pre-run "who hasn't paid" review list (UX deliberation pass — see
     * App\Services\ManuscriptPreRunReviewService's class doc for the exact
     * flagging rule). Deliberately its own on-demand JSON endpoint rather
     * than data baked into index()'s Inertia props — this is the "review
     * before you click Run" step, called only when an admin actually opens
     * that review, not on every Manuscripts/Index.tsx page load. Gated to
     * the identical ability as the run trigger itself
     * (ManuscriptPolicy::calculate() — "whatever role may run the
     * calculation may also preview who it will flag"), not a new,
     * separately-drifting permission tier.
     *
     * Response shape:
     * {
     *   "period": "YYYY-MM",
     *   "summary": {"count": int, "total_exposure": "1234.56"},
     *   "customers": [
     *     {
     *       "uuid": string, "name": string,
     *       "zone_uuid": string|null, "zone_name": string|null,
     *       "phone": string|null, "bill": "2500.00",
     *       "last_payment_date": "YYYY-MM-DD"|null
     *     }, ...
     *   ]
     * }
     */
    public function preRunReview(Request $request): JsonResponse
    {
        $this->authorize('calculate', Manuscript::class);

        // Matches calculate()'s own default/guard — this previews who WOULD
        // be flagged by the run that's about to happen, which governs the
        // upcoming month (2026-08-28 correction, business-rules.md section 2).
        $period = (string) $request->input('period', now()->addMonthNoOverflow()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > now()->addMonthNoOverflow()->format('Y-m')) {
            return response()->json([
                'message' => "Invalid period \"{$period}\" — expected format YYYY-MM, and it cannot be beyond next month.",
            ], 422);
        }

        $zoneUuid = $request->string('zone_uuid')->toString() ?: null;
        $zoneId = $zoneUuid !== null ? $this->zones->findOrFail($zoneUuid)->id : null;

        return response()->json([
            'period' => $period,
            ...$this->preRunReview->reviewList($period, $zoneId),
        ]);
    }

    /**
     * The "large-count" companion to preRunReview() above (task-scheduler.md's
     * 2026-08-27 stage 3 addendum): a full, paginated, zone-filterable board
     * for the same flagged-customer list, in the SAME
     * Disconnections/Index.tsx-style shape — reached via the pre-run review
     * modal/panel's "Review full list" link when the flagged count is too
     * large to render inline. Opened in a NEW browser tab from that link
     * (window.open, not an Inertia visit) so the originating modal/panel's
     * already-fetched state stays alive while the admin works through this
     * list.
     *
     * Deliberately in-memory pagination over ManuscriptPreRunReviewService::
     * reviewList()'s already-computed flagged collection, not a paginated
     * Eloquent query — that service's own flagging logic (credit/prepaid-
     * window exclusion) isn't expressible as a single SQL WHERE clause, and
     * this tenant's real scale (~550 customers total, so at most that many
     * flagged) keeps computing the full list up front cheap. Reuses
     * paginatorProps() below for the same {data, links, meta} shape every
     * other paginated Inertia page on this app already sends.
     */
    public function preRunReviewFull(Request $request): InertiaResponse
    {
        $this->authorize('calculate', Manuscript::class);

        // Matches calculate()'s own default/guard — see preRunReview()'s
        // identical comment (2026-08-28 correction).
        $period = (string) $request->input('period', now()->addMonthNoOverflow()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > now()->addMonthNoOverflow()->format('Y-m')) {
            return redirect()->route('manuscripts.index')->with('error', "Invalid period \"{$period}\" — expected format YYYY-MM, and it cannot be beyond next month.");
        }

        $zoneUuid = $request->string('zone_uuid')->toString() ?: null;
        $zoneId = $zoneUuid !== null ? $this->zones->findOrFail($zoneUuid)->id : null;

        $result = $this->preRunReview->reviewList($period, $zoneId);

        $perPage = 25;
        $page = max(1, (int) $request->input('page', 1));
        $items = collect($result['customers']);

        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Manuscripts/PreRunReviewList', [
            'period' => $period,
            'filters' => ['zone_uuid' => $zoneUuid],
            'summary' => $result['summary'],
            'customers' => $this->paginatorProps($paginator),
            'zones' => $this->zones->all(),
        ]);
    }

    /**
     * Records that a manual WhatsApp bill reminder was sent to $customer
     * (bill-notifications.md section 6.2 / web-admin-spec.md section 3.8's
     * "Send Bill" action). The actual send is entirely client-side — the
     * browser opens the wa.me link itself (Manuscripts/Index.tsx's plain
     * `<a target="_blank">`, no backend round-trip needed to do that part)
     * — this endpoint exists solely to log a `messages` row so there's a
     * record "we reminded this customer", fired via a fire-and-forget
     * router.post() alongside the link opening.
     *
     * Status is deliberately 'link_opened', not 'sent' or 'delivered':
     * a human clicking a wa.me link and then actually typing/sending from
     * their own WhatsApp session is outside this system's visibility —
     * claiming a delivery status we can't verify would be dishonest.
     */
    public function sendBill(Customer $customer): RedirectResponse
    {
        $this->authorize('sendBill', Manuscript::class);

        $content = $this->billNotifications->composeMessage($customer);

        if ($content === null) {
            return redirect()->back()->with('error', "No manuscript found for {$customer->name} yet — nothing to remind them about.");
        }

        Message::create([
            'customer_id' => $customer->id,
            'content' => $content,
            'channel' => 'whatsapp',
            'status' => 'link_opened',
            'type' => 'bill_reminder',
        ]);

        return redirect()->back()->with('success', "Bill reminder logged for {$customer->name}.");
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, links: array<int, array{url: ?string, label: string, active: bool}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    private function paginatorProps(LengthAwarePaginator $paginator): array
    {
        $array = $paginator->toArray();

        return [
            'data' => $array['data'],
            'links' => $array['links'],
            'meta' => [
                'current_page' => $array['current_page'],
                'per_page' => $array['per_page'],
                'total' => $array['total'],
                'last_page' => $array['last_page'],
            ],
        ];
    }
}
