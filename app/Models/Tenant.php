<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\HomepageCompaniesService;
use App\Support\BusinessTimezone;
use Carbon\CarbonImmutable;
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
 * @property bool $show_on_homepage
 * @property string|null $homepage_caption
 * @property string|null $homepage_logo
 * @property string|null $demo_starts_on
 * @property string|null $demo_ends_on
 * @property list<string>|null $demo_reminders_sent
 * @property string|null $activated_at
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

    /**
     * Listed in the public home page's "Cable companies running on CNCMS"
     * strip (App\Services\HomepageCompaniesService). Opt-in: a missing key
     * means not listed. `homepage_caption` and `homepage_logo` (a path on
     * the public disk) are plain VirtualColumn attributes beside it.
     */
    protected function showOnHomepage(): Attribute
    {
        return Attribute::make(
            get: fn (?bool $value) => $value ?? false,
        );
    }

    /**
     * The free demo (config('site.demo_months')): started when a landlord
     * approves a self-service sign-up. `demo_starts_on`/`demo_ends_on` are
     * `Y-m-d` VirtualColumn attributes, the end date being the demo's last
     * full day (Cameroon calendar). From the day after it the workspace is
     * read-only until a landlord activates it (`activated_at`). A tenant
     * with no `demo_ends_on` (swecom, landlord-created tenants) never had a
     * demo and is a full account.
     */
    public function isOnDemo(): bool
    {
        return ! empty($this->demo_ends_on) && empty($this->activated_at);
    }

    /**
     * Days from today to the demo's last day: 0 on the last day, negative
     * once it has ended. Null when not on a demo.
     */
    public function demoDaysLeft(): ?int
    {
        if (! $this->isOnDemo()) {
            return null;
        }

        return (int) CarbonImmutable::parse(BusinessTimezone::today())->diffInDays(CarbonImmutable::parse($this->demo_ends_on), false);
    }

    /** The demo has ended and nobody has activated the workspace yet. */
    public function isReadOnly(): bool
    {
        return ($this->demoDaysLeft() ?? 0) < 0;
    }

    /**
     * Starts the free demo today. Not saved.
     */
    public function startDemo(): void
    {
        $today = CarbonImmutable::parse(BusinessTimezone::today());

        $this->demo_starts_on = $today->toDateString();
        $this->demo_ends_on = $today->addMonthsNoOverflow((int) config('site.demo_months'))->subDay()->toDateString();
        $this->demo_reminders_sent = [];
        $this->activated_at = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => HomepageCompaniesService::forget());
        static::deleted(fn () => HomepageCompaniesService::forget());
    }
}
