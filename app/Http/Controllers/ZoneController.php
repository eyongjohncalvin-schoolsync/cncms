<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ZoneData;
use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Models\Zone;
use App\Services\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('Zones/Create');
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

        $this->zones->delete($zone);

        return redirect()->route('zones.index')->with('success', 'Zone deleted.');
    }

    /**
     * @return array{uuid: string, name: string, town: string, customer_count: int|null}
     */
    private function shapeZone(Zone $zone): array
    {
        return [
            'uuid' => $zone->uuid,
            'name' => $zone->name,
            'town' => $zone->town,
            'customer_count' => $zone->customers_count ?? null,
        ];
    }
}
