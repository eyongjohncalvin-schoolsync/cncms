<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogueResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/services — the tick-list every customer add/edit form (web
 * AND mobile) offers, services.md sections 6-8. Read-only, open to any
 * authenticated tenant member (like ZoneController::index() — knowing the
 * service list isn't sensitive; the real gate is on
 * CustomerPolicy::create()/update() at the point a customer is actually
 * written, enforced by StoreCustomerRequest/UpdateCustomerRequest).
 *
 * Every ACTIVE service + its active options only — unlike the web
 * controller's serviceCatalogue() (CustomerController.php), this does not
 * union in a specific customer's already-held-but-now-inactive rows: the
 * mobile app already has that customer's current `services` from
 * fetchCustomerDetail() (CustomerResource) and merges the two client-side
 * when editing, so the server doesn't need a customer-scoped variant of
 * this endpoint.
 */
class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $services = Service::query()
            ->active()
            ->ordered()
            ->with(['variants' => fn ($query) => $query->active()->ordered()])
            ->get();

        return ServiceCatalogueResource::collection($services)->response();
    }
}
