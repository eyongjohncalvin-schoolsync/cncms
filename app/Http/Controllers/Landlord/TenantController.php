<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HomepageCompaniesService;
use App\Support\BusinessTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Central/platform-level tenant management ("landlord" area) — distinct
 * from the tenant-scoped admin panel SWECOM staff use under
 * app/Http/Controllers/*.php. Manages the `tenants` table itself
 * (onboarding future LCO clients ShalomTech may take on), so every query
 * here runs against the central connection via App\Models\Tenant (always
 * central-pinned, see Stancl's CentralConnection concern).
 *
 * Every action is already gated by the `landlord` middleware alias
 * (App\Http\Middleware\EnsureLandlord) applied in routes/web/landlord.php
 * — see StoreTenantRequest's doc comment for why the Form Requests below
 * don't duplicate that check via a Policy.
 */
class TenantController extends Controller
{
    /**
     * Supports an optional `?status=pending|approved|rejected` filter over
     * `registration_status` (see .ai/skills/cncms/cncms-context/references/
     * self-service-onboarding.md section 4/6) so the landlord can review
     * self-service workspace signups separately from the full tenant list.
     * `registration_status` lives inside the VirtualColumn `data` JSON
     * column (see Tenant::registrationStatus()'s doc comment), not a real
     * table column, so it's filtered in memory rather than via a JSONB
     * query — tenant counts here are small (a handful of rows), so this is
     * not a performance concern worth the added query complexity.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status');

        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = null;
        }

        $tenants = Tenant::query()
            ->with('domains')
            ->get()
            ->filter(fn (Tenant $tenant) => $status === null || $tenant->registration_status === $status)
            ->map(fn (Tenant $tenant) => $this->shape($tenant))
            ->values();

        return Inertia::render('Landlord/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => ['status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Landlord/Tenants/Create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            // Creating a Tenant fires Stancl's provisioning event pipeline
            // (CreateDatabase -> MigrateDatabase -> SeedDatabase): it
            // creates the tenant_{slug} Postgres schema, runs every
            // migration under database/migrations/tenant/, and runs
            // TenantDatabaseSeeder. Same real flow as
            // database/seeders/DatabaseSeeder.php's Tenant::firstOrCreate()
            // call. This takes a few seconds — fine for a low-volume admin
            // action, so no artificial timeout is applied here.
            Tenant::create([
                'id' => $data['slug'],
                'name' => $data['name'],
                'slug' => $data['slug'],
                // A landlord adding a tenant directly through this trusted
                // "Add Tenant" flow is not the public self-service signup
                // path (see routes/web/register.php), so it bypasses the
                // registration_status approval gate entirely.
                'registration_status' => 'approved',
            ]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not provision the new tenant. Please try again.');
        }

        LandlordActivity::record('tenant.created', "Created tenant {$data['name']} ({$data['slug']})", 'tenant', $data['slug']);

        return redirect()->route('landlord.tenants.index')->with('success', 'Tenant created.');
    }

    public function edit(Request $request, Tenant $tenant): Response
    {
        // Members, for support impersonation ("Log in as" — App\Support\
        // Impersonation) and "Reset 2FA" (UserController::resetTwoFactor()).
        // Central data only: the membership index + users.
        $members = User::query()
            ->join('tenant_user_index', 'tenant_user_index.user_id', '=', 'users.id')
            ->where('tenant_user_index.tenant_id', $tenant->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.uuid', 'users.name', 'users.email', 'users.status', 'users.is_landlord', 'users.two_factor_secret', 'users.two_factor_confirmed_at', 'tenant_user_index.role']);

        return Inertia::render('Landlord/Tenants/Edit', [
            'tenant' => $this->shape($tenant),
            'members' => $members->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getAttribute('role'),
                'can_impersonate' => $user->status === 'active' && ! $user->is_landlord,
                'can_reset_two_factor' => $user->hasTwoFactorEnabled() && $user->id !== $request->user()->id,
            ])->values(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        // Cast explicitly rather than mass-assigning $request->validated():
        // the 'boolean' validation rule accepts "0"/"1" without coercing
        // the value's PHP type, and both fields are stored as real JSON
        // booleans inside the tenant's `data` column (see Tenant::isActive,
        // Tenant::bulkWhatsappEnabled). Each field is only written when
        // present — the Edit page submits the active/inactive toggle and
        // the bulk-WhatsApp entitlement toggle as two separate <Form>s.
        $updates = [];

        if ($request->has('is_active')) {
            $updates['is_active'] = $request->boolean('is_active');
        }

        if ($request->has('bulk_whatsapp_enabled')) {
            $updates['bulk_whatsapp_enabled'] = $request->boolean('bulk_whatsapp_enabled');
        }

        if ($request->has('show_on_homepage')) {
            return $this->updateHomepageListing($request, $tenant);
        }

        if ($updates !== []) {
            $tenant->update($updates);

            $changes = collect($updates)->map(fn (bool $value, string $key) => $key.' '.($value ? 'on' : 'off'))->implode(', ');
            LandlordActivity::record('tenant.updated', "Updated tenant {$tenant->name}: {$changes}", 'tenant', $tenant->id, $updates);
        }

        return redirect()->route('landlord.tenants.index')->with('success', 'Tenant updated.');
    }

    /**
     * The tenant's place in the public home page's "Cable companies running
     * on CNCMS" strip (App\Services\HomepageCompaniesService): shown or not,
     * the line under its name, and its logo. Saving the tenant clears the
     * strip's cache, so the home page changes at once.
     */
    private function updateHomepageListing(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $disk = Storage::disk(HomepageCompaniesService::LOGO_DISK);
        $oldLogo = $tenant->homepage_logo;

        $tenant->show_on_homepage = $request->boolean('show_on_homepage');
        $tenant->homepage_caption = trim((string) $request->validated('homepage_caption')) ?: null;

        if ($request->hasFile('homepage_logo')) {
            $tenant->homepage_logo = $request->file('homepage_logo')->storeAs(
                'homepage-logos',
                $tenant->id.'-'.now()->format('YmdHis').'.'.$request->file('homepage_logo')->extension(),
                HomepageCompaniesService::LOGO_DISK,
            );
        } elseif ($request->boolean('remove_homepage_logo')) {
            $tenant->homepage_logo = null;
        }

        $tenant->save();

        if ($oldLogo && $oldLogo !== $tenant->homepage_logo) {
            $disk->delete($oldLogo);
        }

        LandlordActivity::record(
            'tenant.homepage',
            ($tenant->show_on_homepage ? 'Listed ' : 'Unlisted ')."{$tenant->name} on the home page",
            'tenant',
            $tenant->id,
            ['show_on_homepage' => $tenant->show_on_homepage, 'caption' => $tenant->homepage_caption, 'logo_changed' => $oldLogo !== $tenant->homepage_logo],
        );

        return back()->with('success', 'Home page listing saved.');
    }

    /**
     * Approves a pending self-service workspace registration. Per the
     * onboarding spec (section 4), this is checked per-request by
     * ResolveTenantWeb/ResolveTenant — no re-login is required for the
     * registrant to reach their dashboard on their next request.
     *
     * A notification email to the registrant would be a natural follow-up
     * here (contact_email isn't currently captured on Tenant, and wiring a
     * mail driver/template is real scope beyond this change) — not built,
     * left as a nice-to-have.
     */
    public function approve(Tenant $tenant): RedirectResponse
    {
        $tenant->registration_status = 'approved';
        $tenant->rejection_reason = null;

        // A self-service sign-up starts its free demo the day it is approved
        // (Tenant::startDemo); re-approving never restarts one.
        $startsDemo = empty($tenant->demo_ends_on) && empty($tenant->activated_at);

        if ($startsDemo) {
            $tenant->startDemo();
        }

        $tenant->save();

        LandlordActivity::record('tenant.approved', "Approved workspace {$tenant->name}", 'tenant', $tenant->id, $startsDemo ? ['demo_ends_on' => $tenant->demo_ends_on] : null);

        $suffix = $startsDemo ? ' Free demo until '.CarbonImmutable::parse($tenant->demo_ends_on)->format('j M Y').'.' : '';

        return redirect()->route('landlord.tenants.index')->with('success', "Workspace \"{$tenant->name}\" approved.{$suffix}");
    }

    /**
     * Moves the free demo's last day: extends it, shortens it, or (for a
     * full account) puts the workspace on a demo. A date in the past makes
     * the workspace read-only at once. Reminders start over for the new date.
     */
    public function updateDemo(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate(['demo_ends_on' => ['required', 'date_format:Y-m-d']]);

        $tenant->demo_starts_on ??= BusinessTimezone::today();
        $tenant->demo_ends_on = $validated['demo_ends_on'];
        $tenant->demo_reminders_sent = [];
        $tenant->activated_at = null;
        $tenant->save();

        LandlordActivity::record('tenant.demo_changed', "Set {$tenant->name}'s free demo to end on {$tenant->demo_ends_on}", 'tenant', $tenant->id, ['demo_ends_on' => $tenant->demo_ends_on]);

        return back()->with('success', $tenant->isReadOnly()
            ? "The demo has ended, so {$tenant->name} is now read-only."
            : "{$tenant->name}'s free demo now ends on ".CarbonImmutable::parse($tenant->demo_ends_on)->format('j M Y').'.');
    }

    /**
     * Ends the demo for good: a full account, nothing read-only. Its demo
     * dates are kept for the record (and the contract).
     */
    public function activate(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->isOnDemo()) {
            return back()->with('error', "{$tenant->name} is already a full account.");
        }

        $tenant->activated_at = now()->toIso8601String();
        $tenant->save();

        LandlordActivity::record('tenant.activated', "Activated {$tenant->name} as a full account", 'tenant', $tenant->id);

        return back()->with('success', "{$tenant->name} is now a full account. Everyone can save again.");
    }

    /**
     * Rejects a pending self-service workspace registration. The tenant
     * (and its schema/data) is intentionally NOT deleted — per the
     * onboarding spec, a rejected workspace stays inert but available for
     * the landlord to review/audit manually later.
     */
    public function reject(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->registration_status = 'rejected';
        // rejection_reason is a VirtualColumn attribute like is_active/
        // registration_status (see App\Models\Tenant's doc comments) — it
        // doesn't need its own Attribute accessor to be stored, since
        // Stancl's VirtualColumn trait routes any attribute that isn't a
        // real table column into the `data` JSON column automatically.
        $tenant->rejection_reason = trim((string) $request->input('reason')) ?: null;
        $tenant->save();

        LandlordActivity::record('tenant.rejected', "Rejected workspace {$tenant->name}", 'tenant', $tenant->id, ['reason' => $tenant->rejection_reason]);

        return redirect()->route('landlord.tenants.index')->with('success', "Workspace \"{$tenant->name}\" rejected.");
    }

    /**
     * @return array{id: string, name: string, slug: string, domain: string|null, is_active: bool, registration_status: string, rejection_reason: string|null, bulk_whatsapp_enabled: bool, show_on_homepage: bool, homepage_caption: string|null, homepage_logo_url: string|null, created_at: string|null, demo_starts_on: string|null, demo_ends_on: string|null, demo_days_left: int|null, activated_at: string|null}
     */
    private function shape(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domains->first()?->domain,
            'is_active' => (bool) $tenant->is_active,
            'registration_status' => $tenant->registration_status,
            'rejection_reason' => $tenant->rejection_reason ?? null,
            'bulk_whatsapp_enabled' => (bool) $tenant->bulk_whatsapp_enabled,
            'show_on_homepage' => (bool) $tenant->show_on_homepage,
            'homepage_caption' => $tenant->homepage_caption ?? null,
            'homepage_logo_url' => $tenant->homepage_logo ? Storage::disk(HomepageCompaniesService::LOGO_DISK)->url($tenant->homepage_logo) : null,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'demo_starts_on' => $tenant->demo_starts_on ?? null,
            'demo_ends_on' => $tenant->demo_ends_on ?? null,
            'demo_days_left' => $tenant->demoDaysLeft(),
            'activated_at' => $tenant->activated_at ?? null,
        ];
    }
}
