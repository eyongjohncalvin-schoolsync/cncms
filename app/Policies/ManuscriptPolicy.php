<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Manuscripts are read-only over the API — the manuscript:calculate command
 * is the only writer (see App\Services\ManuscriptCalculator). Viewing is
 * restricted per the role table's "View manuscripts" row: super/admin/
 * manager/agent — workers are excluded (unlike Zone/Customer, which are
 * open to everyone). Exporting the full register (business-rules.md
 * section 4 / api-spec.md section 9.2) is restricted per the role table's
 * "Export data" row: super/admin/manager only — agents cannot export.
 */
class ManuscriptPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent');
    }

    public function view(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent');
    }

    public function export(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    /**
     * Running manuscript:calculate from the web panel (web-admin-spec.md
     * section 3.8's "Run Manuscript Calculation" button) is restricted per
     * business-rules.md section 10's role table: super/admin only.
     */
    public function calculate(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    /**
     * "Send Bill" (manual WhatsApp reminder — bill-notifications.md section
     * 6.2) is a per-customer action on the same page manuscripts are
     * viewed on, so it's gated to the same roles as view()/viewAny()
     * rather than a stricter set.
     */
    public function sendBill(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent');
    }
}
