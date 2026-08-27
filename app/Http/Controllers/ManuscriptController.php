<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Message;
use App\Services\BillNotificationService;
use App\Services\ManuscriptGenerationBatchService;
use App\Services\ManuscriptService;
use App\Services\ZoneService;
use Barryvdh\DomPDF\Facade\Pdf;
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
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly ZoneService $zones,
        private readonly BillNotificationService $billNotifications,
        private readonly ManuscriptGenerationBatchService $batches,
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
            'payment_expiration' => $manuscript->payment_expiration,
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
     * trigger. Per task-scheduler.md section 4.1, this now dispatches the
     * same chunked, queued Bus::batch() mechanism the scheduled path uses
     * (thousands-of-customers robustness applies to a manually-triggered
     * run exactly as much as a scheduled one), rather than running
     * manuscript:calculate synchronously inline in this request.
     *
     * Only the review GATE stays scheduled-path-only: this call passes
     * autoPublish=true, so once every chunk finishes computing, the batch's
     * then() callback commits straight to `manuscripts` with no
     * pending_review pause — matching this button's pre-existing immediate-
     * commit behavior. What changes is that "immediate" is no longer
     * "before this HTTP request returns" — with a real queue connection,
     * commit happens once a worker processes the batch, shortly after this
     * redirect. See App\Services\ManuscriptGenerationBatchService.
     */
    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('calculate', Manuscript::class);

        $period = (string) $request->input('period', now()->format('Y-m'));

        // A bad/mistyped period reaching this endpoint previously ran and
        // auto-published billing for the ENTIRE tenant against that literal
        // string (dispatch() with no $customerIds resolves every customer) —
        // a stray future period (e.g. "2031-02") fabricated real, non-frozen
        // manuscript rows for every customer, corrupting BillNotificationService's
        // "latest manuscript" for all of them at once. Mirrors
        // StoreArrearsAdjustmentRequest's target_period validation (format +
        // not-in-the-future) since the same invariant applies here.
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > now()->format('Y-m')) {
            return redirect()->back()->with('error', "Invalid period \"{$period}\" — expected format YYYY-MM, and it cannot be in the future.");
        }

        $this->batches->dispatch($period, scheduledTask: null, autoPublish: true, actingUserId: Auth::id());

        return redirect()->back()->with('success', "Manuscript calculation for {$period} started — refresh the page shortly to see the results.");
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
