<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resources → Suppliers: who the business pays. New or changed suppliers
 * wait for a second person; staff phone matches are shown as a warning.
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = $this->suppliers->list();

        return Inertia::render('Resources/Suppliers', [
            'suppliers' => $suppliers->map(fn (Supplier $supplier): array => [
                ...(new SupplierResource($supplier))->resolve($request),
                'staff_matches' => $this->suppliers->staffMatches($supplier),
                'is_mine' => $supplier->created_by === $request->user()->id,
            ])->values()->all(),
            'can_manage' => $request->user()->can('update', Supplier::class),
            'can_create' => $request->user()->can('create', Supplier::class),
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = $this->suppliers->create($request->validated(), $request->user());

        return back()->with('success', "{$supplier->name} added — another person must approve it before it can be paid without review.");
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $this->suppliers->update($supplier, $request->validated(), $request->user());

        return back()->with('success', 'Supplier updated.');
    }

    public function approve(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $this->suppliers->approve($supplier, $request->user());

        return back()->with('success', "{$supplier->name} approved.");
    }

    public function deactivate(Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $this->suppliers->deactivate($supplier);

        return back()->with('success', "{$supplier->name} switched off.");
    }
}
