<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property string $registration_status
 * @property bool $bulk_whatsapp_enabled
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * `is_active` is not a real column — see the tenants table migration
     * (database/migrations/2019_09_15_000010_create_tenants_table.php):
     * only `id`, timestamps, and a `data` JSON column exist. Stancl's
     * VirtualColumn trait (via HasDataColumn) stores any other attribute,
     * including `name` and `slug`, inside `data` automatically. Tenants
     * provisioned before this flag existed (e.g. the seeded "swecom"
     * tenant) simply have no `is_active` key in `data`, so treat a
     * missing/null value as active rather than requiring a backfill.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (?bool $value) => $value ?? true,
        );
    }

    /**
     * Gates self-service-registered tenants behind landlord approval
     * (see .ai/skills/cncms/cncms-context/references/self-service-onboarding.md).
     * Same VirtualColumn mechanism as is_active. Tenants provisioned before
     * this existed (swecom, and anything created via the trusted Landlord
     * "Add Tenant" flow) have no key in `data` — treat missing as
     * 'approved' rather than retroactively locking out existing tenants.
     */
    protected function registrationStatus(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ?? 'approved',
        );
    }

    public function isApproved(): bool
    {
        return $this->registration_status === 'approved';
    }

    /**
     * Landlord-controlled entitlement gating BULK Twilio WhatsApp sending
     * (see .ai/skills/cncms/cncms-context/references/bill-notifications.md
     * section 3) — distinct from the always-available, free manual `wa.me`
     * mode, which needs no entitlement at all. Same VirtualColumn mechanism
     * as is_active/registration_status above. Unlike is_active, a missing
     * key defaults to `false`: this is an opt-in paid entitlement ShalomTech
     * grants per-tenant, not a legacy flag that must default "on" to avoid
     * retroactively locking out existing tenants.
     */
    protected function bulkWhatsappEnabled(): Attribute
    {
        return Attribute::make(
            get: fn (?bool $value) => $value ?? false,
        );
    }
}
