<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\CustomerData;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Zone;
use App\Services\CustomerService;
use App\Services\ManuscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Web (session-auth, Inertia) counterpart to Api\CustomerController. Reuses
 * the same CustomerService and the same StoreCustomerRequest/
 * UpdateCustomerRequest Form Requests (so validation and the create/update
 * authorization gate live in exactly one place), but renders Inertia pages
 * and redirects with flash session data instead of returning JSON — see
 * AuthController's doc comment for why web pages never call /api/v1/*
 * directly.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly ManuscriptService $manuscripts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->only(['zone_uuid', 'status', 'level', 'search']);

        $paginator = $this->customers->list($filters, 15);

        return Inertia::render('Customers/Index', [
            'customers' => [
                'data' => collect($paginator->items())
                    ->map(fn (Customer $customer) => $this->shapeCustomer($customer))
                    ->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'zones' => $this->zonesForSelect(),
            'filters' => [
                'zone_uuid' => $filters['zone_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'level' => $filters['level'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('Customers/Create', [
            'zones' => $this->zonesForSelect(),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->customers->create(CustomerData::fromArray($request->validated()));

        return redirect()->route('customers.index')->with('success', 'Customer created.');
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer = $this->customers->findOrFail($customer->uuid);

        return Inertia::render('Customers/Show', [
            'customer' => $this->shapeCustomerDetail($customer),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Customers/Edit', [
            'customer' => $this->shapeCustomer($customer->loadMissing('zone')),
            'zones' => $this->zonesForSelect(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, CustomerData::fromArray($request->validated()));

        return redirect()->route('customers.index')->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $this->customers->delete($customer);

        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    /**
     * Streams the customer's bill slip PDF, reusing the exact same
     * ManuscriptService::billData()/resources/views/pdf/bill.blade.php view
     * that Api\BillController uses. This is a dedicated web route rather
     * than a redirect to /api/v1/bills/{uuid}/print because that API route
     * requires Sanctum bearer auth, which a browser session doesn't carry.
     */
    public function printBill(Request $request, Customer $customer): SymfonyResponse
    {
        $this->authorize('printBill', $customer);

        $period = $request->string('period')->value() ?: null;

        $data = $this->manuscripts->billData($customer, $period);

        return Pdf::loadView('pdf.bill', $data)->stream("bill-{$customer->uuid}.pdf");
    }

    /**
     * @return array<int, array{uuid: string, name: string, town: string}>
     */
    private function zonesForSelect(): array
    {
        return Zone::query()
            ->orderBy('name')
            ->get(['uuid', 'name', 'town'])
            ->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'town' => $zone->town,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeCustomer(Customer $customer): array
    {
        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'bill' => $customer->bill,
            'others' => $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
            'location' => $customer->location,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeCustomerDetail(Customer $customer): array
    {
        return [
            ...$this->shapeCustomer($customer),
            'description' => $customer->description,
            'created_at' => $customer->created_at?->toISOString(),
            'manuscript' => $customer->latestManuscript ? [
                'uuid' => $customer->latestManuscript->uuid,
                'bill' => $customer->latestManuscript->bill,
                'total_arrears' => $customer->latestManuscript->total_arrears,
                'credit' => $customer->latestManuscript->credit,
                'total_bill' => $customer->latestManuscript->total_bill,
                'payment_expiration' => $customer->latestManuscript->payment_expiration,
                'period' => $customer->latestManuscript->period,
            ] : null,
            'recent_payments' => $customer->payments->map(fn ($payment) => [
                'uuid' => $payment->uuid,
                'amount' => $payment->amount,
                'credit' => $payment->credit,
                'frequency' => $payment->frequency,
                'verification_status' => $payment->verification_status,
                'created_at' => $payment->created_at?->toISOString(),
            ])->all(),
        ];
    }
}
