<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\ReportService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /reports feature — Daily/Weekly/Monthly operational + financial
 * reporting (see App\Services\ReportService's class doc). One route, one
 * page component (resources/tsx/pages/Reports/Index.tsx): the server picks
 * the tier's payload and scope, the frontend picks the layout, per role —
 * exactly the "same route, server decides payload/scope" approach the task
 * spec calls for, mirroring how DashboardController/ManuscriptController
 * already render one Inertia page per area rather than branching by role
 * server-side into different components.
 */
class ReportController extends Controller
{
    /**
     * @var list<string>
     */
    private const TIERS = ['daily', 'weekly', 'monthly'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('view', Report::class);

        $requestedTier = (string) $request->query('tier', '');
        $tier = in_array($requestedTier, self::TIERS, true) ? $requestedTier : $this->defaultTierForRole();

        $date = $request->query('date');
        $date = $date !== null && $date !== '' ? (string) $date : null;

        $report = match ($tier) {
            'daily' => $this->reports->daily($date),
            'weekly' => $this->reports->weekly($date),
            default => $this->reports->monthly($date),
        };

        return Inertia::render('Reports/Index', [
            'tier' => $tier,
            'date' => $date,
            'report' => $report,
            'can_export' => $this->context->isAnyOf('super', 'admin', 'manager'),
        ]);
    }

    /**
     * Monthly tier only — see App\Services\ReportService::exportMonthly()
     * (bypasses the report cache entirely, same convention as
     * ManuscriptService::exportData()) and ManuscriptController::export()'s
     * doc comment for the Barryvdh DomPDF convention this mirrors.
     * 'throttle:exports' is layered on in routes/web/reports.php, same as
     * manuscripts/export.
     */
    public function export(Request $request): Response
    {
        $this->authorize('export', Report::class);

        // A monthly PDF is a much smaller document than the full manuscript
        // register ManuscriptController::export() renders (one period's
        // summary blocks, not one row per customer), so 512M — matching
        // Api\BillController's single-record precedent — is plenty here.
        // Deliberately set per-request rather than left unset: this
        // endpoint's web sibling omitting an explicit memory_limit is a
        // known, separately-tracked bug (see ManuscriptController::export()
        // web variant) — not one to silently repeat here.
        ini_set('memory_limit', '512M');

        $period = $request->query('date');
        $period = $period !== null && $period !== '' ? (string) $period : null;

        $data = $this->reports->exportMonthly($period);

        return Pdf::loadView('pdf.report-monthly', $data)
            ->setPaper('a4', 'portrait')
            ->stream('monthly-report-'.$data['period'].'.pdf');
    }

    /**
     * Landing tier when no/invalid ?tier= is given — per the task spec's
     * role-appropriate default: agent (mobile, field use) lands on the
     * lightest tier; manager lands on Weekly (their branch's operating
     * rhythm); super/admin land on Monthly (the full financial picture).
     */
    private function defaultTierForRole(): string
    {
        return match ($this->context->role) {
            'agent' => 'daily',
            'manager' => 'weekly',
            default => 'monthly',
        };
    }
}
