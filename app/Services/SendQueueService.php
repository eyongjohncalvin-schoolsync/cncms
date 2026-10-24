<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\CameroonPhone;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The "To send" lists: receipts and monthly bills that haven't reached the
 * customer yet, so staff can work through them one click at a time
 * (WhatsApp opens with the message ready; email and printing go through the
 * usual receipt actions). An item leaves the list as soon as anything is
 * recorded in its sent_log — a send, a print of a printed-receipt
 * customer's receipt, or an explicit "Skip".
 *
 * Fenced like every other list: a branch-restricted user sees their branch,
 * an agent only their own zone.
 */
class SendQueueService
{
    /** Older receipts are history, not a to-do — they never clutter the list. */
    public const int RECEIPT_WINDOW_DAYS = 60;

    public const string CHANNEL_SKIPPED = 'skipped';

    public function __construct(
        private readonly BillNotificationService $bills,
    ) {}

    public function pendingReceipts(int $perPage = 50): LengthAwarePaginator
    {
        return $this->pendingReceiptsQuery()
            ->with(['payment.customer.zone'])
            ->orderBy('issued_at')
            ->paginate($perPage, pageName: 'receipts_page');
    }

    public function pendingReceiptCount(): int
    {
        return $this->pendingReceiptsQuery()->count();
    }

    /**
     * The latest billing month with bills — the one being sent out.
     */
    public function currentBillPeriod(): ?string
    {
        return Manuscript::query()->max('period');
    }

    public function pendingBills(int $perPage = 50): LengthAwarePaginator
    {
        return $this->pendingBillsQuery()
            ->with(['customer.zone'])
            ->join('customers', 'customers.id', '=', 'manuscripts.customer_id')
            ->orderBy('customers.name')
            ->select('manuscripts.*')
            ->paginate($perPage, pageName: 'bills_page');
    }

    public function pendingBillCount(): int
    {
        return $this->pendingBillsQuery()->count();
    }

    /**
     * The ready-to-open WhatsApp link for a bill row (null: no usable phone).
     */
    public function billWhatsappLink(Manuscript $manuscript): ?string
    {
        return $this->bills->waLink($manuscript->customer, $manuscript);
    }

    /**
     * Records a bill as sent on its manuscript (called from the existing
     * "Send Bill" action), so it leaves the bills list.
     */
    public function recordBillSent(Manuscript $manuscript, ?User $actor, string $channel, ?string $to): void
    {
        $log = $manuscript->sent_log ?? [];
        $log[] = ['channel' => $channel, 'at' => now()->toIso8601String(), 'by' => $actor?->id, 'to' => $to];

        $manuscript->update(['sent_log' => $log]);
    }

    public function skipReceipt(PaymentReceipt $receipt, User $actor): void
    {
        app(ReceiptWhatsAppService::class)->recordSent($receipt, self::CHANNEL_SKIPPED, $actor, '');
    }

    public function skipBill(Manuscript $manuscript, User $actor): void
    {
        $this->recordBillSent($manuscript, $actor, self::CHANNEL_SKIPPED, null);
    }

    /**
     * Issued, not void, recent, nothing in sent_log yet — and, for a
     * customer who prefers a printed receipt, not printed yet either.
     */
    private function pendingReceiptsQuery(): Builder
    {
        return PaymentReceipt::query()
            ->where('status', PaymentReceipt::STATUS_ISSUED)
            ->where('issued_at', '>=', Carbon::now()->subDays(self::RECEIPT_WINDOW_DAYS))
            ->whereRaw("sent_log = '[]'::jsonb")
            ->whereHas('payment.customer', function (Builder $customer): void {
                $this->fence($customer);
                $customer->where(fn (Builder $inner) => $inner
                    ->where('preferred_channel', '!=', 'print')
                    ->orWhereRaw('payment_receipts.print_count = 0'));
            });
    }

    /**
     * Bills of the current month for active customers with something to
     * pay (the same rule bulk bill printing uses), not yet sent.
     */
    private function pendingBillsQuery(): Builder
    {
        $period = $this->currentBillPeriod() ?? '';

        return Manuscript::query()
            ->where('manuscripts.period', $period)
            ->where('manuscripts.total_bill', '>', 0)
            ->whereRaw("manuscripts.sent_log = '[]'::jsonb")
            ->whereHas('customer', function (Builder $customer): void {
                $customer->where('status', 'active');
                $this->fence($customer);
            });
    }

    private function fence(Builder $customer): void
    {
        $branchId = TenantContext::currentBranchId();
        $zoneId = TenantContext::currentZoneId();

        if ($zoneId !== null) {
            $customer->where('zone_id', $zoneId);
        }

        if ($branchId !== null) {
            $customer->where('customers.branch_id', $branchId);
        }
    }

    /**
     * How a receipt row is delivered, given the customer's preference and
     * what's on file — WhatsApp falls back from a missing email, and a row
     * with no way to reach the customer says so.
     *
     * @return array{channel: string, problem: ?string}
     */
    public static function receiptDelivery(Customer $customer, bool $emailEnabled): array
    {
        $hasPhone = CameroonPhone::forWhatsapp($customer->phone) !== null;

        return match ($customer->preferred_channel ?? 'whatsapp') {
            'print' => ['channel' => 'print', 'problem' => null],
            'email' => $customer->email && $emailEnabled
                ? ['channel' => 'email', 'problem' => null]
                : ['channel' => 'whatsapp', 'problem' => $hasPhone ? null : 'No email (or email switched off) and no WhatsApp number'],
            default => ['channel' => 'whatsapp', 'problem' => $hasPhone ? null : 'No WhatsApp number on file'],
        };
    }
}
