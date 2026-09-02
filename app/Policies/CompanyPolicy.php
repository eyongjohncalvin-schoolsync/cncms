<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Company info (single-row tenant settings record): everyone with tenant
 * access can view; only super/admin can update, matching web-admin-spec.md's
 * nav spec ("SETTINGS [admin only]") and business-rules.md section 10.
 */
class CompanyPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function view(User $user): bool
    {
        return $this->context->can('company.view');
    }

    public function update(User $user): bool
    {
        return $this->context->can('company.update');
    }
}
