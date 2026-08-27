<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ZoneData;
use App\Exports\ZoneImportTemplateExport;
use App\Http\Requests\ImportZonesRequest;
use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Models\Zone;
use App\Services\BranchService;
use App\Services\ZoneImportService;
use App\Services\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Web (session-auth, Inertia) counterpart to Api\ZoneController — see
 * CustomerController's doc comment for the shared rationale. Zones are a
 * simple two-field resource (name, town), so there is no dedicated show
 * page — index/create/edit/destroy only.
 */
class ZoneController extends Controller
{
    public function __construct(
        private readonly ZoneService $zones,
        private readonly ZoneImportService $zoneImports,
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Zone::class);

        $filters = $request->only(['search']);

        $paginator = $this->zones->list($filters, 15);

        return Inertia::render('Zones/Index', [
            'zones' => [
                'data' => collect($paginator->items())
                    ->map(fn (Zone $zone) => $this->shapeZone($zone))
                    ->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'filters' => [
                'search' => $filters['search'] ?? null,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Zone::class);

        return Inertia::render('Zones/Create', [
            'branches' => $this->branchesForSelect(),
        ]);
    }

    public function store(StoreZoneRequest $request): RedirectResponse
    {
        $this->zones->create(ZoneData::fromArray($request->validated()));

        return redirect()->route('zones.index')->with('success', 'Zone created.');
    }

    public function edit(Zone $zone): Response
    {
        $this->authorize('update', $zone);

        return Inertia::render('Zones/Edit', [
            'zone' => $this->shapeZone($zone),
            'branches' => $this->branchesForSelect(),
        ]);
    }

    public function update(UpdateZoneRequest $request, Zone $zone): RedirectResponse
    {
        $this->zones->update($zone, ZoneData::fromArray($request->validated()));

        return redirect()->route('zones.index')->with('success', 'Zone updated.');
    }

    public function destroy(Zone $zone): RedirectResponse
    {
        $this->authorize('delete', $zone);

        try {
            $this->zones->delete($zone);
        } catch (ValidationException $e) {
            // Converted to this app's established flash('error') convention
            // rather than left as an automatic errors-bag redirect — this
            // page has no form/field bound to a 'zone' error key, so a
            // validation-errors redirect here would render nothing visible.
            return redirect()->route('zones.index')->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('zones.index')->with('success', 'Zone deleted.');
    }

    /**
     * Bulk zone import from an .xlsx spreadsheet — see
     * App\Services\ZoneImportService. Reports a partial success (some
     * created, some skipped) rather than treating any single row failure
     * as fatal for the whole file, mirroring storeBulk-style bulk actions
     * elsewhere in the app (e.g. PaymentController::storeBulk()).
     */
    public function import(ImportZonesRequest $request): RedirectResponse
    {
        $result = $this->zoneImports->import($request->file('file'), $request->user());

        $succeededCount = count($result['succeeded']);
        $failedCount = count($result['failed']);

        $message = $succeededCount === 1 ? '1 zone imported.' : "{$succeededCount} zones imported.";

        if ($failedCount > 0) {
            $message .= ' '.($failedCount === 1 ? '1 row failed — see the import report below.' : "{$failedCount} rows failed — see the import report below.");
        }

        return redirect()->route('zones.index')
            ->with($succeededCount > 0 ? 'success' : 'error', $message)
            ->with('import', [
                'type' => 'zones',
                'succeeded_count' => $succeededCount,
                'failed_count' => $failedCount,
                'failed' => collect($result['failed'])
                    ->map(fn (string $reason, int $row): array => ['row' => $row, 'reason' => $reason])
                    ->values()
                    ->all(),
            ]);
    }

    /**
     * GET /zones/import/template — downloads a blank zone_upload.xlsx with
     * the exact header row import() expects (App\Imports\
     * ZonesImport::COLUMNS, the single source of truth both this template
     * and the real import read from), so an operator can fill in a
     * correctly formatted spreadsheet before uploading instead of guessing
     * the layout. Same 'create' gate as the upload itself and as a manual
     * "Add Zone" form (ZonePolicy::create()).
     */
    public function importTemplate(): BinaryFileResponse
    {
        $this->authorize('create', Zone::class);

        return Excel::download(new ZoneImportTemplateExport, 'zone_import_template.xlsx');
    }

    /**
     * @return array{uuid: string, name: string, town: string, customer_count: int|null, branch_uuid: string|null, branch_name: string|null}
     */
    private function shapeZone(Zone $zone): array
    {
        return [
            'uuid' => $zone->uuid,
            'name' => $zone->name,
            'town' => $zone->town,
            'customer_count' => $zone->customers_count ?? null,
            'branch_uuid' => $zone->branch?->uuid,
            'branch_name' => $zone->branch?->name,
        ];
    }

    /**
     * Minimal {uuid, name} list backing the Zone create/edit branch picker
     * — mirrors CustomerController::zonesForSelect()'s shape.
     *
     * @return array<int, array{uuid: string, name: string}>
     */
    private function branchesForSelect(): array
    {
        return $this->branches->all()
            ->map(fn ($branch) => ['uuid' => $branch->uuid, 'name' => $branch->name])
            ->values()
            ->all();
    }
}
