<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\CustomerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisconnectCustomerRequest;
use App\Http\Requests\ReconnectCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\SuspendCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\CustomerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerStatusService $statuses,
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

    /**
     * PATCH /api/v1/customers/{customer}/disconnect. Policy ability:
     * 'disconnect' (super/admin/manager). Body: {note?: string}.
     */
    public function disconnect(DisconnectCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->statuses->disconnect($customer, $request->validated('note'));

        return (new CustomerResource($customer))->additional(['message' => 'Customer disconnected'])->response();
    }

    /**
     * PATCH /api/v1/customers/{customer}/suspend. Policy ability: 'suspend'
     * (super/admin/manager). Body: {reason: tv_problem|poor_service|customer_request|
     * zone_transfer|other, note?: string (required when reason=other)}.
     */
    public function suspend(SuspendCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->statuses->suspend($customer, $request->validated('reason'), $request->validated('note'));

        return (new CustomerResource($customer))->additional(['message' => 'Customer suspended'])->response();
    }

    /**
     * PATCH /api/v1/customers/{customer}/reconnect. Policy ability:
     * 'reconnect' (super/admin/manager). Body: {note?: string,
     * include_fine?: bool (2026-08 owner decision: admin-discretion
     * opt-in, unchecked/false by default, for EITHER 'disconnected' or
     * 'suspended' — see business-rules.md section 6),
     * arrears_payment?: string (optional partial/full arrears payment,
     * single-customer reconnect only — see ReconnectCustomerRequest)}.
     */
    public function reconnect(ReconnectCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->statuses->reconnect(
            $customer,
            $request->validated('note'),
            $request->boolean('include_fine'),
            $request->validated('arrears_payment'),
        );

        return (new CustomerResource($customer))->additional(['message' => 'Customer reconnected'])->response();
    }
}
