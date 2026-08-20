<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ZoneData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Zone;
use App\Services\ZoneService;
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

        $perPage = (int) $request->integer('per_page', 25);

        $zones = $this->zones->list($request->only(['search']), $perPage);

        return ZoneResource::collection($zones)->response();
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
