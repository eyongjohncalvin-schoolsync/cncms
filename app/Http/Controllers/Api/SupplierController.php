<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;

/**
 * Suppliers for the app's expense form: the active and still-pending ones
 * (a pending supplier can be picked; the expense then waits for a
 * person), and adding a new one in the field (it starts pending).
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = $this->suppliers->list()->where('status', '!=', Supplier::STATUS_INACTIVE)->values();

        return SupplierResource::collection($suppliers)->response();
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = $this->suppliers->create($request->validated(), $request->user());

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }
}
