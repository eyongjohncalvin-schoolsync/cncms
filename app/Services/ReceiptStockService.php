<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FieldDevice;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\User;
use App\Support\BranchAttribution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receipt numbers handed out in blocks (docs/plans/cash-controls.md,
 * wave 2). The system creates every number; each one is tied to the person
 * holding it and must end used or cancelled.
 *
 * Digital blocks top themselves up: whenever a registered phone has fewer
 * than TOP_UP_BELOW unused numbers it is given another DIGITAL_BLOCK_SIZE —
 * nobody has to remember to issue them, and an offline phone always has
 * numbers in hand. Books are issued deliberately by a stock manager.
 */
class ReceiptStockService
{
    public const int DIGITAL_BLOCK_SIZE = 50;

    public const int TOP_UP_BELOW = 20;

    /** Never more than this many open digital blocks per phone. */
    public const int MAX_OPEN_DIGITAL_BLOCKS = 2;

    /** @var list<int> */
    public const array BOOK_SIZES = [25, 50, 100];

    public function __construct(
        private readonly PaymentReceiptService $receipts,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function allocate(string $kind, User $holder, int $size, ?FieldDevice $device = null, ?User $allocatedBy = null): ReceiptBlock
    {
        return DB::transaction(function () use ($kind, $holder, $size, $device, $allocatedBy): ReceiptBlock {
            ['year' => $year, 'first' => $first] = $this->receipts->reserveNumbers($size);

            $block = ReceiptBlock::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::RECEIPT_BLOCK),
                'kind' => $kind,
                'holder_user_id' => $holder->id,
                'branch_id' => $device !== null ? $device->branch_id : BranchAttribution::forUser($holder),
                'field_device_id' => $device?->id,
                'year' => $year,
                'first_sequence' => $first,
                'last_sequence' => $first + $size - 1,
                'status' => 'open',
                'allocated_by' => $allocatedBy?->id,
            ]);

            $now = now();
            $rows = [];

            for ($sequence = $first; $sequence < $first + $size; $sequence++) {
                $rows[] = [
                    'receipt_block_id' => $block->id,
                    'number' => ReceiptLeaf::formatNumber($year, $sequence),
                    'status' => ReceiptLeaf::STATUS_UNUSED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                ReceiptLeaf::query()->insert($chunk);
            }

            return $block;
        });
    }

    /**
     * The unused numbers a phone may issue, topping up first when it is
     * running low. Called every time the app syncs.
     *
     * @return Collection<int, ReceiptLeaf>
     */
    public function digitalStockFor(FieldDevice $device): Collection
    {
        $unused = $this->unusedDigitalLeaves($device);

        $openBlocks = ReceiptBlock::query()
            ->where('field_device_id', $device->id)
            ->where('kind', ReceiptBlock::KIND_DIGITAL)
            ->where('status', 'open')
            ->count();

        if ($unused->count() < self::TOP_UP_BELOW && $openBlocks < self::MAX_OPEN_DIGITAL_BLOCKS) {
            $this->allocate(ReceiptBlock::KIND_DIGITAL, $device->user, self::DIGITAL_BLOCK_SIZE, $device);
            $unused = $this->unusedDigitalLeaves($device);
        }

        // A fully used digital block closes itself, so it stops counting
        // against MAX_OPEN_DIGITAL_BLOCKS.
        ReceiptBlock::query()
            ->where('field_device_id', $device->id)
            ->where('status', 'open')
            ->whereDoesntHave('leaves', fn ($leaves) => $leaves->where('status', ReceiptLeaf::STATUS_UNUSED))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        return $unused;
    }

    /**
     * @return Collection<int, ReceiptLeaf>
     */
    private function unusedDigitalLeaves(FieldDevice $device): Collection
    {
        return ReceiptLeaf::query()
            ->where('status', ReceiptLeaf::STATUS_UNUSED)
            ->whereHas('block', fn ($block) => $block
                ->where('field_device_id', $device->id)
                ->where('kind', ReceiptBlock::KIND_DIGITAL)
                ->where('status', 'open'))
            ->orderBy('number')
            ->get();
    }

    /**
     * A number that will never carry money — a misprint, a torn leaf, a
     * receipt the printer jammed on. The reason is kept for the audit.
     */
    public function cancelLeaf(ReceiptLeaf $leaf, string $reason, User $actor): ReceiptLeaf
    {
        return DB::transaction(function () use ($leaf, $reason, $actor): ReceiptLeaf {
            $locked = ReceiptLeaf::query()->whereKey($leaf->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUnused()) {
                throw ValidationException::withMessages([
                    'reason' => "Receipt {$locked->number} is already {$locked->status} — only an unused number can be cancelled.",
                ]);
            }

            $locked->update([
                'status' => ReceiptLeaf::STATUS_CANCELLED,
                'cancelled_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Take a block out of circulation (a book handed back, a holder who
     * left). With $cancelRemainingReason every still-unused number is
     * cancelled with that reason — the book came back and its blank leaves
     * were checked. Without it they stay unused and are reported as
     * unaccounted until someone explains them.
     */
    public function closeBlock(ReceiptBlock $block, User $actor, ?string $cancelRemainingReason = null): ReceiptBlock
    {
        return DB::transaction(function () use ($block, $actor, $cancelRemainingReason): ReceiptBlock {
            if ($cancelRemainingReason !== null) {
                $block->leaves()->getQuery()
                    ->where('status', ReceiptLeaf::STATUS_UNUSED)
                    ->get()
                    ->each(fn (ReceiptLeaf $leaf) => $leaf->update([
                        'status' => ReceiptLeaf::STATUS_CANCELLED,
                        'cancelled_reason' => $cancelRemainingReason,
                        'cancelled_by' => $actor->id,
                        'cancelled_at' => now(),
                    ]));
            }

            if ($block->isOpen()) {
                $block->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id]);
            }

            return $block->refresh();
        });
    }

    /**
     * Per-block leaf counts for the stock page, keyed by block id.
     *
     * @param  list<int>  $blockIds
     * @return array<int, array{used: int, cancelled: int, unused: int, scanned_unused: int}>
     */
    public function countsFor(array $blockIds): array
    {
        $counts = [];

        ReceiptLeaf::query()
            ->whereIn('receipt_block_id', $blockIds)
            ->selectRaw('receipt_block_id, status, count(*) as total, count(scanned_unused_at) as scanned')
            ->groupBy('receipt_block_id', 'status')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[$row->receipt_block_id] ??= ['used' => 0, 'cancelled' => 0, 'unused' => 0, 'scanned_unused' => 0];
                $counts[$row->receipt_block_id][$row->status] = (int) $row->total;

                if ($row->status === ReceiptLeaf::STATUS_UNUSED) {
                    $counts[$row->receipt_block_id]['scanned_unused'] = (int) $row->scanned;
                }
            });

        return $counts;
    }
}
