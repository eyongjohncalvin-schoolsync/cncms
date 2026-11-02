<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompensationBatch;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Zone- and branch-wide compensation (App\Services\CompensationService):
 * asked for by whoever may ask for free months (`compensation.request`),
 * always decided by someone else who approves (`compensation.approve`) —
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
        return $this->context->can('compensation.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('compensation.request');
    }

    public function approve(User $user, CompensationBatch $batch): bool
    {
        return $batch->status === CompensationBatch::STATUS_PENDING_APPROVAL
            && $this->context->can('compensation.approve')
            && ($user->id !== $batch->requested_by || $this->context->isSuper());
    }

    public function reject(User $user, CompensationBatch $batch): bool
    {
        return $this->approve($user, $batch);
    }
}
