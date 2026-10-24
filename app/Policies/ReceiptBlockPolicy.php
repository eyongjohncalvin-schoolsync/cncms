<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\ReceiptBlock;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Receipt stock (docs/plans/cash-controls.md, wave 2).
 *
 *  - `receipts.manage_stock` sees every block, issues printed books, closes
 *    blocks, cancels unused numbers and revokes phones.
 *  - Anyone who records payments may register their own phone and draw
 *    digital numbers for it (issueOwn) — the numbers are always tied to
 *    them, so this grants nothing over anyone else's stock.
 *  - A holder may cancel an unused number from their OWN stock (a printer
 *    jam); cancelling someone else's needs `receipts.manage_stock`.
 */
class ReceiptBlockPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('receipts.manage_stock');
    }

    public function create(User $user): bool
    {
        return $this->context->can('receipts.manage_stock');
    }

    public function update(User $user, ReceiptBlock $block): bool
    {
        return $this->context->can('receipts.manage_stock');
    }

    public function issueOwn(User $user): bool
    {
        return $user->can('create', Payment::class);
    }

    public function cancelLeaf(User $user, ReceiptBlock $block): bool
    {
        if ($block->holder_user_id === $user->id) {
            return true;
        }

        // Branches v2: a fenced stock manager only cancels numbers in their branch's books.
        $fence = TenantContext::currentBranchId();

        return $this->context->can('receipts.manage_stock') && ($fence === null || $block->branch_id === $fence);
    }
}
