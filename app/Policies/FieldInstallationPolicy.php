<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FieldInstallation;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Field registrations awaiting the office (docs/plans/installation-and-
 * promotions.md, phase 3). Activate / reject need `installations.activate`
 * and a registration still pending. Maker ≠ checker is not required (the
 * agent who registered never holds the permission by default); who decided
 * and why is recorded on the row (Auditable). The branch fence comes from
 * route binding (ScopesRouteBindingToBranch).
 */
class FieldInstallationPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function activate(User $user, FieldInstallation $fieldInstallation): bool
    {
        return $fieldInstallation->isPending() && $this->context->can('installations.activate');
    }

    public function reject(User $user, FieldInstallation $fieldInstallation): bool
    {
        return $this->activate($user, $fieldInstallation);
    }
}
