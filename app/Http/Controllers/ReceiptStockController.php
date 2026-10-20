<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FieldDevice;
use App\Models\PaymentReceipt;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\FieldDeviceService;
use App\Services\ReceiptBookService;
use App\Services\ReceiptStockService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Receipt stock — who holds which receipt numbers, and whether every number
 * is accounted for (docs/plans/cash-controls.md, wave 2). Gated
 * `receipts.manage_stock` (ReceiptBlockPolicy).
 */
class ReceiptStockController extends Controller
{
    public function __construct(
        private readonly ReceiptStockService $stock,
        private readonly ReceiptBookService $books,
        private readonly FieldDeviceService $devices,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReceiptBlock::class);

        $status = in_array($request->query('status'), ['open', 'closed', 'all'], true) ? $request->query('status') : 'open';
        $search = trim((string) $request->query('search', ''));

        $blocks = ReceiptBlock::query()
            ->with(['holder', 'fieldDevice'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(200)
            ->get();

        $counts = $this->stock->countsFor($blocks->pluck('id')->all());

        return Inertia::render('ReceiptStock/Index', [
            'filters' => ['status' => $status, 'search' => $search],
            'lookup' => $search !== '' ? $this->lookup($search) : null,
            'blocks' => $blocks->map(fn (ReceiptBlock $block): array => [
                'uuid' => $block->uuid,
                'number' => $block->number,
                'kind' => $block->kind,
                'status' => $block->status,
                'holder_name' => $block->holder?->name,
                'device_name' => $block->fieldDevice?->name,
                'first_number' => $block->firstNumber(),
                'last_number' => $block->lastNumber(),
                'size' => $block->size(),
                'counts' => $counts[$block->id] ?? ['used' => 0, 'cancelled' => 0, 'unused' => 0, 'scanned_unused' => 0],
                'created_at' => $block->created_at?->toIso8601String(),
            ])->values()->all(),
            'devices' => FieldDevice::query()->with('user')->latest('id')->limit(100)->get()->map(fn (FieldDevice $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'user_name' => $device->user?->name,
                'registered_at' => $device->registered_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'revoked_at' => $device->revoked_at?->toIso8601String(),
            ])->values()->all(),
            'holders' => $this->holderOptions(),
            'book_sizes' => ReceiptStockService::BOOK_SIZES,
        ]);
    }

    public function storeBook(Request $request): RedirectResponse
    {
        $this->authorize('create', ReceiptBlock::class);

        $validated = $request->validate([
            'holder_user_id' => ['required', 'integer', Rule::in(array_column($this->holderOptions(), 'id'))],
            'size' => ['required', 'integer', Rule::in(ReceiptStockService::BOOK_SIZES)],
        ]);

        $block = $this->stock->allocate(
            ReceiptBlock::KIND_BOOK,
            User::query()->findOrFail($validated['holder_user_id']),
            (int) $validated['size'],
            null,
            $request->user(),
        );

        return redirect()->route('receipt-stock.index')
            ->with('success', "Receipt book {$block->number} issued ({$block->firstNumber()} – {$block->lastNumber()}). Print it and hand it over.");
    }

    public function bookPdf(ReceiptBlock $receiptBlock): HttpResponse
    {
        $this->authorize('update', $receiptBlock);
        abort_unless($receiptBlock->kind === ReceiptBlock::KIND_BOOK, 404);

        return $this->books->pdf($receiptBlock)->download("receipt-book-{$receiptBlock->number}.pdf");
    }

    public function close(Request $request, ReceiptBlock $receiptBlock): RedirectResponse
    {
        $this->authorize('update', $receiptBlock);

        $validated = $request->validate([
            'cancel_remaining' => ['required', 'boolean'],
            'reason' => ['required_if:cancel_remaining,true', 'nullable', 'string', 'max:255'],
        ]);

        $this->stock->closeBlock($receiptBlock, $request->user(), $validated['cancel_remaining'] ? $validated['reason'] : null);

        return back()->with('success', "Block {$receiptBlock->number} closed.");
    }

    public function cancelLeaf(Request $request, string $number): RedirectResponse
    {
        $leaf = ReceiptLeaf::query()->with('block')->where('number', strtoupper($number))->firstOrFail();
        $this->authorize('cancelLeaf', $leaf->block);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->stock->cancelLeaf($leaf, $validated['reason'], $request->user());

        return back()->with('success', "Receipt number {$leaf->number} cancelled.");
    }

    public function revokeDevice(Request $request, FieldDevice $fieldDevice): RedirectResponse
    {
        $this->authorize('create', ReceiptBlock::class);

        $this->devices->revoke($fieldDevice, $request->user());

        return back()->with('success', "{$fieldDevice->name} revoked — receipts signed on it are no longer accepted.");
    }

    /**
     * One receipt number, end to end: who holds it, what happened to it,
     * and the payment it carried.
     *
     * @return array<string, mixed>|null
     */
    private function lookup(string $search): ?array
    {
        $leaf = ReceiptLeaf::query()->with(['block.holder', 'cancelledBy'])->where('number', strtoupper($search))->first();
        $receipt = PaymentReceipt::query()->with('payment.customer')->where('receipt_number', strtoupper($search))->first();

        if ($leaf === null && $receipt === null) {
            return ['number' => strtoupper($search), 'found' => false];
        }

        return [
            'number' => strtoupper($search),
            'found' => true,
            'leaf_status' => $leaf?->status,
            'block_number' => $leaf?->block?->number,
            'holder_name' => $leaf?->block?->holder?->name,
            'cancelled_reason' => $leaf?->cancelled_reason,
            'cancelled_by_name' => $leaf?->cancelledBy?->name,
            'scanned_unused_at' => $leaf?->scanned_unused_at?->toIso8601String(),
            'receipt' => $receipt ? [
                'status' => $receipt->status,
                'source' => $receipt->source,
                'amount' => (string) $receipt->amount,
                'issued_at' => $receipt->issued_at?->toIso8601String(),
                'customer_name' => $receipt->payment?->customer?->name,
                'payment_uuid' => $receipt->payment?->uuid,
            ] : null,
        ];
    }

    /**
     * Everyone in this workspace who can be handed a receipt book.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    private function holderOptions(): array
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context()->tenantUser->tenant_id)
            ->with('user')
            ->get()
            ->filter(fn (TenantUser $tenantUser): bool => $tenantUser->user !== null)
            ->map(fn (TenantUser $tenantUser): array => [
                'id' => $tenantUser->user_id,
                'name' => $tenantUser->user->name,
                'role' => $tenantUser->role,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Resolved per call, not injected: the router keeps a controller instance
     * for the life of the app, and the tenant context is per request.
     */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
