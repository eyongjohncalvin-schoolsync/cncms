<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return redirect()->route('landlord.tenants.index')->with('success', 'Tenant created.');
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Landlord/Tenants/Edit', [
            'tenant' => $this->shape($tenant),
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

        if ($updates !== []) {
            $tenant->update($updates);
        }

        return redirect()->route('landlord.tenants.index')->with('success', 'Tenant updated.');
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
        $tenant->save();

        return redirect()->route('landlord.tenants.index')->with('success', "Workspace \"{$tenant->name}\" approved.");
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

        return redirect()->route('landlord.tenants.index')->with('success', "Workspace \"{$tenant->name}\" rejected.");
    }

    /**
     * @return array{id: string, name: string, slug: string, domain: string|null, is_active: bool, registration_status: string, rejection_reason: string|null, bulk_whatsapp_enabled: bool, created_at: string|null}
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
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
