<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Manuscript;
use App\Models\PaymentReceipt;
use App\Services\ReceiptEmailService;
use App\Services\SendQueueService;
use App\Support\CameroonPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "To send" page — receipts and this month's bills still waiting to
 * reach the customer (App\Services\SendQueueService). Receipts need
 * `payments.view`, bills `manuscripts.send_bill`; each tab only shows when
 * the user holds its permission.
 */
class SendQueueController extends Controller
{
    public function __construct(
        private readonly SendQueueService $queue,
        private readonly ReceiptEmailService $email,
    ) {}

    public function index(Request $request): Response
    {
        $canReceipts = $request->user()->can('payments.view');
        $canBills = $request->user()->can('manuscripts.send_bill');
        abort_unless($canReceipts || $canBills, 403);

        $tab = $request->query('tab') === 'bills' && $canBills ? 'bills' : ($canReceipts ? 'receipts' : 'bills');
        $period = $this->queue->currentBillPeriod();
        $emailEnabled = $this->email->emailEnabled();

        return Inertia::render('SendQueue/Index', [
            'tab' => $tab,
            'can' => ['receipts' => $canReceipts, 'bills' => $canBills],
            'counts' => [
                'receipts' => $canReceipts ? $this->queue->pendingReceiptCount() : 0,
                'bills' => $canBills ? $this->queue->pendingBillCount() : 0,
            ],
            'bill_period' => $period,
            'bill_period_label' => $period ? Carbon::createFromFormat('!Y-m', $period)->format('F Y') : null,
            'receipt_window_days' => SendQueueService::RECEIPT_WINDOW_DAYS,
            'receipts' => $tab === 'receipts' ? $this->paginated($this->queue->pendingReceipts(), fn (PaymentReceipt $receipt): array => [
                'uuid' => $receipt->uuid,
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => $receipt->issued_at?->toIso8601String(),
                'amount' => (string) $receipt->amount,
                'payment_uuid' => $receipt->payment?->uuid,
                'customer_uuid' => $receipt->payment?->customer?->uuid,
                'customer_name' => $receipt->payment?->customer?->name,
                'zone_name' => $receipt->payment?->customer?->zone?->name,
                'phone' => $receipt->payment?->customer?->phone,
                'email' => $receipt->payment?->customer?->email,
                'download_url' => route('payment-receipts.pdf', $receipt),
                ...SendQueueService::receiptDelivery($receipt->payment->customer, $emailEnabled),
            ]) : null,
            'bills' => $tab === 'bills' ? $this->paginated($this->queue->pendingBills(), fn (Manuscript $manuscript): array => [
                'uuid' => $manuscript->uuid,
                'customer_uuid' => $manuscript->customer?->uuid,
                'customer_name' => $manuscript->customer?->name,
                'zone_name' => $manuscript->customer?->zone?->name,
                'phone' => $manuscript->customer?->phone,
                'total_bill' => (string) $manuscript->total_bill,
                'wa_link' => $this->queue->billWhatsappLink($manuscript),
                'problem' => CameroonPhone::forWhatsapp($manuscript->customer?->phone) === null ? 'No WhatsApp number on file' : null,
            ]) : null,
        ]);
    }

    public function skipReceipt(Request $request, PaymentReceipt $receipt): RedirectResponse
    {
        abort_unless($request->user()->can('payments.view'), 403);

        $this->queue->skipReceipt($receipt, $request->user());

        return back()->with('success', "Receipt {$receipt->receipt_number} removed from the list.");
    }

    public function skipBill(Request $request, Manuscript $manuscript): RedirectResponse
    {
        abort_unless($request->user()->can('manuscripts.send_bill'), 403);

        $this->queue->skipBill($manuscript, $request->user());

        return back()->with('success', 'Bill removed from the list.');
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $shape
     * @return array{data: list<array<string, mixed>>, links: array<int, mixed>, meta: array{current_page: int, last_page: int, total: int}}
     */
    private function paginated(LengthAwarePaginator $paginator, callable $shape): array
    {
        $paginator->withQueryString();
        $array = $paginator->toArray();

        return [
            'data' => array_values(array_map($shape, $paginator->items())),
            'links' => $array['links'],
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ];
    }
}
