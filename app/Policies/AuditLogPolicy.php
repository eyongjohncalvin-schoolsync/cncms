<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * The audit trail is append-only and never mutated via the app (see
 * audit-strategy.md section 2's "append-only" design principle) — there are
 * deliberately no create/update/delete abilities here. Viewing is
 * restricted per the role matrix's "View audit logs" row: super/admin/
 * manager only, matching PaymentVerificationPolicy's viewing restriction.
 */
class AuditLogPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('audit.view');
    }
}
