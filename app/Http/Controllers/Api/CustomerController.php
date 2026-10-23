<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\InstallationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisconnectCustomerRequest;
use App\Http\Requests\PreviewCustomerInstallationRequest;
use App\Http\Requests\ReconnectCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\SuspendCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\CustomerEligibilityService;
use App\Services\CustomerInstallationService;
use App\Services\CustomerService;
use App\Services\CustomerStatusService;
use App\Services\FreeMonthGrantService;
use App\Services\InstallationOfferService;
use App\Services\ZoneService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerStatusService $statuses,
        private readonly CustomerEligibilityService $eligibility,
        private readonly ZoneService $zones,
        private readonly TenantContext $context,
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

    /**
     * One save, one transaction (installation phase 2): the customer and,
     * when the mobile app sends them (online only), the installation
     * charge and payment, an advance and the free months. Older builds
     * send none of those fields and get exactly what they got before — plus
     * the first billed month being the month after joining (D4). `payments`
     * lists every payment taken, each with its receipt number.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $result = app(CustomerInstallationService::class)->create(
            CustomerData::fromArray($request->validated()),
            InstallationData::fromArray($request->validated()),
            $request->user(),
        );

        $customer = $this->customers->findOrFail($result['customer']->uuid);

        return (new CustomerResource($customer))->additional([
            'payments' => array_map(fn (Payment $payment): array => [
                'uuid' => $payment->uuid,
                'amount' => (string) $payment->amount,
                'purpose' => $payment->purpose,
                'months' => $payment->months,
                'verification_status' => $payment->verification_status,
                'receipt_number' => $payment->receipt()->value('receipt_number'),
            ], $result['payments']),
        ])->response()->setStatusCode(201);
    }

    /**
     * GET /customers/installation-options — what the mobile add-customer
     * screen needs for its "Installation & first payment" section.
     */
    public function installationOptions(): JsonResponse
    {
        $this->authorize('create', Customer::class);

        return response()->json(['data' => [
            'offers' => app(InstallationOfferService::class)->availableForForm(),
            'max_free_months' => FreeMonthGrantService::maxMonths(),
            'can_manage' => $this->context->can('installations.manage'),
            'can_take_payment' => $this->context->can('payments.create'),
            'today' => BusinessTimezone::today(),
        ]]);
    }

    /**
     * POST /customers/installation-preview — the live preview line, the
     * advance amount, what needs approval and possible duplicates. Writes
     * nothing. Same service as the web form.
     */
    public function installationPreview(PreviewCustomerInstallationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => app(CustomerInstallationService::class)->preview(
            CustomerData::fromArray(['services' => $validated['services'] ?? []])->services ?? [],
            InstallationData::fromArray($validated),
            $validated['name'] ?? null,
            $validated['phone'] ?? null,
        )]);
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

    /**
     * GET /api/v1/customers/eligible-for-disconnection. The mobile
     * counterpart to App\Http\Controllers\DisconnectionsController::
     * eligibilityIndex()'s `?eligible=1` web tab — same policy ability
     * (viewEligibilityBoard, which admits super/admin/manager/agent) and
     * the exact same underlying App\Services\CustomerEligibilityService
     * query, reused rather than reimplemented (mobile-app-react-native.md
     * §12/Disconnections screen). Registered ahead of the apiResource in
     * routes/api/customers.php so it isn't swallowed by the `{customer}`
     * show route.
     *
     * Zone scoping is force-applied server-side from TenantContext::zoneId
     * for the `agent` role — resolved once by ResolveTenant from the
     * caller's own Agent row (App\Support\TenantContext::resolve()), the
     * same mechanism PaymentPolicy::verify()'s zone fence already relies
     * on. Any `zone_uuid` query value an agent sends is deliberately
     * ignored, exactly like eligibilityIndex() ignores it for agents —
     * an agent cannot see another zone's flagged customers by tampering
     * with the query string. Office roles (super/admin/manager) may
     * optionally pass `zone_uuid` to filter; omitted, they see every zone.
     */
    public function eligibleForDisconnection(Request $request): JsonResponse
    {
        $this->authorize('viewEligibilityBoard', Customer::class);

        $zoneUuid = $request->string('zone_uuid')->toString() ?: null;

        if ($this->context->is('agent')) {
            $zoneId = $this->context->zoneId;
        } elseif ($zoneUuid !== null) {
            $zoneId = $this->zones->findOrFail($zoneUuid)->id;
        } else {
            $zoneId = null;
        }

        $eligible = $this->eligibility->eligibleForDisconnection($zoneId);

        return response()->json(['data' => $eligible->values()->all()]);
    }
}
