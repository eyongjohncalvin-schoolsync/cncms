<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Notification settings (single-row tenant settings record, same shape as
 * CompanyPolicy): everyone with tenant access can view; only super/admin
 * can update, matching CompanyPolicy exactly per this feature's spec
 * (.ai/skills/cncms/cncms-context/references/bill-notifications.md
 * section 3's "UI split" note).
 */
class NotificationSettingPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function view(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }
}
