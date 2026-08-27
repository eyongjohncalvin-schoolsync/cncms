<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManuscriptHistoryResource;
use App\Http\Resources\ManuscriptResource;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Services\ManuscriptService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManuscriptController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly TenantContext $context,
    ) {}

    /**
     * Zone scoping is force-applied server-side from TenantContext::zoneId
     * for the `agent` role — mirrors CustomerController::
     * eligibleForDisconnection()'s exact pattern (resolved once by
     * ResolveTenant from the caller's own Agent row). Any `zone_uuid` query
     * value an agent sends is deliberately ignored, so an agent cannot see
     * another zone's manuscript figures by tampering with the query string.
     * Office roles (super/admin/manager) may optionally pass `zone_uuid` to
     * filter; omitted, they see every zone — unchanged from before this
     * fix. Found and closed alongside the mobile Manuscript screen
     * (mobile-app-react-native.md section 13): this endpoint predates the
     * eligible-for-disconnection precedent and had never had the same fence
     * applied.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Manuscript::class);

        $perPage = (int) $request->integer('per_page', 25);
        $filters = $request->only(['period', 'zone_uuid', 'status']);

        if ($this->context->is('agent')) {
            unset($filters['zone_uuid']);
            $filters['zone_id'] = $this->context->zoneId;
        }

        $manuscripts = $this->manuscripts->list($filters, $perPage);
        $summary = $this->manuscripts->summary($filters);

        return ManuscriptResource::collection($manuscripts)
            ->additional([
                'period' => $filters['period'] ?? now()->format('Y-m'),
                'summary' => $summary,
            ])
            ->response();
    }

    public function history(Customer $customer): JsonResponse
    {
        $this->authorize('viewAny', Manuscript::class);

        $history = $this->manuscripts->history($customer);

        return ManuscriptHistoryResource::collection($history)->response();
    }

    /**
     * Full monthly billing register as one combined multi-page PDF (see
     * business-rules.md section 4). The legacy v1 ZIP-of-JPEGs export is
     * deliberately simplified to a single PDF for this rebuild — the
     * `format` query param from api-spec.md section 9.2 is accepted but
     * ignored; only PDF is produced.
     */
    public function export(Request $request): Response
    {
        $this->authorize('export', Manuscript::class);

        $filters = $request->only(['period', 'zone_uuid', 'status']);

        $data = $this->manuscripts->exportData($filters);

        // See BillController::print()'s comment — dompdf's memory overhead
        // exceeds PHP's default 128M limit; this export can span hundreds
        // of customer rows in one document, so give it more headroom than
        // the single-record bill print.
        ini_set('memory_limit', '1024M');

        return Pdf::loadView('pdf.manuscript', $data)
            ->setPaper('a4', 'landscape')
            ->stream('manuscript-'.$data['period'].'.pdf');
    }
}
