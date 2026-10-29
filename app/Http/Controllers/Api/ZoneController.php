<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ZoneData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Zone;
use App\Services\ZonePerformanceService;
use App\Services\ZoneService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function __construct(
        private readonly ZoneService $zones,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Zone::class);

        // Capped: the app asks for 100 (every zone); no request loads unbounded rows.
        $perPage = min(max(1, $request->integer('per_page', 25)), 500);

        $zones = $this->zones->list($request->only(['search']), $perPage);

        return ZoneResource::collection($zones)->response();
    }

    /**
     * How the caller's zones collect this month (owner, 2026-10-29): an
     * agent sees only the zones they cover; whoever sees the cash book sees
     * every zone of their branch; anyone else sees none.
     */
    public function performance(ZonePerformanceService $zonePerformance, TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', Zone::class);

        $period = BusinessTimezone::currentPeriod();
        $zones = collect($zonePerformance->forPeriod($period));
        $zoneIds = $context->zoneIds;

        if ($zoneIds !== null) {
            $mine = Zone::query()->whereIn('id', $zoneIds)->pluck('uuid');
            $zones = $zones->whereIn('uuid', $mine);
        } elseif (! $context->can('cash.view')) {
            $zones = collect();
        }

        return response()->json([
            'data' => $zones->values()->all(),
            'meta' => ['period' => $period, 'judged' => BusinessTimezone::now()->day >= ZonePerformanceService::JUDGE_FROM_DAY],
        ]);
    }

    public function show(Zone $zone): JsonResponse
    {
        $this->authorize('view', $zone);

        return (new ZoneResource($zone->loadCount('customers')->load('agents')))->response();
    }

    public function store(StoreZoneRequest $request): JsonResponse
    {
        $zone = $this->zones->create(ZoneData::fromArray($request->validated()));

        return (new ZoneResource($zone))->response()->setStatusCode(201);
    }

    public function update(UpdateZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone = $this->zones->update($zone, ZoneData::fromArray($request->validated()));

        return (new ZoneResource($zone))->response();
    }

    public function destroy(Zone $zone): JsonResponse
    {
        $this->authorize('delete', $zone);

        $this->zones->delete($zone);

        return response()->json(['message' => 'Zone deleted']);
    }
}
