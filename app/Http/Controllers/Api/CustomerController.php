<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\CustomerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $perPage = (int) $request->integer('per_page', 25);

        $filters = $request->only(['zone_uuid', 'status', 'level', 'search', 'has_phone']);

        if ($request->has('has_phone')) {
            $filters['has_phone'] = $request->boolean('has_phone');
        }

        $customers = $this->customers->list($filters, $perPage);

        return CustomerResource::collection($customers)->response();
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer = $this->customers->findOrFail($customer->uuid);

        return (new CustomerResource($customer))->response();
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create(CustomerData::fromArray($request->validated()));

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->update($customer, CustomerData::fromArray($request->validated()));

        return (new CustomerResource($customer))->response();
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->customers->delete($customer);

        return response()->json(['message' => 'Customer deleted']);
    }
}
