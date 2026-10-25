<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompensationBatch;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Zone- and branch-wide compensation (App\Services\CompensationService):
 * asked for by whoever may give free months (`installations.manage`),
 * always decided by someone else who approves (`installations.approve`) —
 * the owner excepted, exactly like one customer's free months
 * (FreeMonthGrantPolicy).
 */
class CompensationBatchPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('installations.manage') || $this->context->can('installations.approve');
    }

    public function create(User $user): bool
    {
        return $this->context->can('installations.manage');
    }

    public function approve(User $user, CompensationBatch $batch): bool
    {
        return $batch->status === CompensationBatch::STATUS_PENDING_APPROVAL
            && $this->context->can('installations.approve')
            && ($user->id !== $batch->requested_by || $this->context->isSuper());
    }

    public function reject(User $user, CompensationBatch $batch): bool
    {
        return $this->approve($user, $batch);
    }
}
