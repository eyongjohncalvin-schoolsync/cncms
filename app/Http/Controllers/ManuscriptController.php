<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Manuscript;
use App\Models\Zone;
use App\Services\ManuscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
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
        ]);

        return Inertia::render('Manuscripts/Index', [
            'period' => $period,
            'filters' => $filters,
            'manuscripts' => $this->paginatorProps($paginated),
            'summary' => $this->manuscripts->summary($filters),
            'zones' => Zone::query()->orderBy('name')->get(['uuid', 'name', 'town']),
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
     * Runs manuscript:calculate synchronously (the dataset is small — see
     * web-admin-spec.md section 3.8's "Run Manuscript Calculation" button,
     * gated admin/super only via ManuscriptPolicy::calculate()).
     */
    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('calculate', Manuscript::class);

        $period = (string) $request->input('period', now()->format('Y-m'));

        Artisan::call('manuscript:calculate', ['period' => $period]);

        return redirect()->back()->with('success', "Manuscript calculated for {$period}.");
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
