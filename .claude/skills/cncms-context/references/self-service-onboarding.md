# Self-Service Onboarding & Visual Redesign — Design Spec

Status: **Design, implementation starting** | Owner ask: central login, Google-account signup,
self-service workspace creation, landlord approval gate, and a visual redesign of the login and
dashboard pages.

---

## 1. Why

CNCMS was built single-tenant-in-practice (SWECOM only), with tenants provisioned manually by
whoever runs `Tenant::create()` (currently only exposed via the Landlord section, itself
admin-only). To let ShalomTech onboard other LCO clients without hand-holding each one, new
operators should be able to sign up themselves, describe their company, and get a working
workspace — gated by a landlord approval step so an anonymous signup can't self-provision
unsupervised access to a real paying customer's data.

## 2. Visual direction

Two reference template *families* informed this (their general layout/aesthetic conventions
inspired the direction below — this is a from-scratch reimplementation with this app's existing
Tailwind v4 + Headless UI stack, not literal asset reuse; the source template sites weren't
directly viewable/scrapable during implementation, so the specifics below are the concrete brief
each agent should build to):

**Login/Register/Welcome (Adminator-style):** a centered card (max-width ~400-440px) on a plain
light background, generous vertical padding, the product name/logo above the form, form fields
with soft rounded borders (`rounded-lg`), a full-width primary button, a lighter-weight
secondary/link row below (switch between sign-in/sign-up), and — for the register flow only — a
"Continue with Google" button placed above the email/password fields with a divider ("or
continue with email") beneath it. No illustration/split-screen needed — keep it a single centered
column, consistent with `resources/tsx/layouts/AuthLayout.tsx`'s existing shape, just visually
refined (spacing, typography weight, subtle shadow on the card, a slightly larger/bolder
wordmark).

**Dashboard (CoreUI-style):** the current `AppLayout.tsx` sidebar+topbar structure stays
structurally — that part already matches CoreUI's own layout convention (fixed-width left
sidebar with icon+label nav, topbar with page title + user menu). The redesign work is about
visual polish, not restructuring: colored/icon-accented stat cards (each stat card gets a small
colored icon chip, not just plain text), a slightly denser/more "dashboard-like" information
layout (the existing stat-card grid stays, but consider adding the two small charts
`web-admin-spec.md` originally specified — income vs. expenses, collection rate by zone — using
`recharts`, which is already a dependency and already used on `Resources/Dashboard.tsx`), a
recent-activity feed if time allows (skip if it adds real scope — a static/omitted section is
fine, don't block on it), and sidebar nav items getting distinct accent colors on hover/active
state rather than the current single blue tone for every item (CoreUI's sidebars commonly
color-code nav sections). Keep the existing component library (`Card`, `Badge`, `StatCard`, etc.)
— extend/restyle them, don't fork a parallel component system.

## 3. Auth architecture (already true, stays true)

CNCMS resolves tenancy server-side from the authenticated user's tenant membership (via the
central `tenant_user_index` table — see `app/Http/Middleware/ResolveTenant(Web).php`), not from
subdomain/domain. There is already exactly **one** login page (`/login`) for every tenant's
users — nothing architecturally new is needed for "central login," it already works this way.
What's new is *who can reach it via self-registration* and *what happens between signup and
first successful login*.

## 4. Registration & workspace-creation flow

```
Visitor lands on "/" (guest)
        |
        v
Welcome page: [Log in] [Sign up]
        |
        v (Sign up)
Register page:
  - "Continue with Google" (Socialite OAuth) -> skips password entirely
  - OR email + password + name (classic form)
        |
        v
Step 2 — Company/workspace info:
  - Company name, location, contact phone, MOMO numbers (same fields as
    the existing Settings > Company Info form — reuse that form's shape)
  - Workspace slug (auto-suggested from company name, editable, validated
    unique against `tenants`)
        |
        v
On submit:
  1. Central `User` created (or matched, if this was a Google-linked
     existing account) — `status` stays 'active' (auth works immediately),
     this is NOT what's gated.
  2. `Tenant::create([...])` — fires Stancl's provisioning pipeline
     exactly like the existing Landlord "Add Tenant" flow, seeding zones/
     categories/company via TenantDatabaseSeeder, with the submitted
     company info overwriting the seeded placeholder Company row for the
     new tenant.
  3. `TenantUser::create(['role' => 'super', ...])` inside the new tenant
     — the registrant fully owns their own workspace (see section 6 for
     why 'super' here does NOT grant landlord access).
  4. New `registration_status` field on the Tenant (see section 5) is set
     to 'pending'.
        |
        v
User is redirected to a "Pending approval" holding page — NOT the
dashboard. Session is authenticated, tenant membership exists, but
ResolveTenant(Web) blocks access until registration_status = 'approved'
(see section 5).
        |
        v
Landlord reviews (Landlord > Pending Workspaces, a new tab/filter on the
existing Landlord > Tenants page) -> Approve or Reject.
  - Approve: registration_status = 'approved'. User can now reach the
    dashboard on their next request (no re-login needed — the gate is
    checked per-request, not baked into the session).
  - Reject: registration_status = 'rejected'. User sees a rejection
    message on the holding page; workspace stays inert (no auto-delete —
    a rejected tenant is data the landlord may still want to review/audit
    manually).
```

## 5. Data model changes

`Tenant` already uses Stancl's VirtualColumn pattern (no physical columns beyond `id`/timestamps
— attributes route through a `data` JSON column). The Landlord section already added `is_active`
this way. Add, the same way:

- `registration_status` — `'pending' | 'approved' | 'rejected'`, default `'pending'` on create.
  Tenants created via the *existing* Landlord "Add Tenant" flow (an admin acting directly, not a
  public signup) should be created with `registration_status = 'approved'` already — that flow
  is inherently trusted, only the new public self-service path needs the gate.
- `contact_email` / `requested_by_user_id` (optional, nice-to-have for the landlord's review
  screen to show who's asking) — include if trivial, skip if it adds real friction.

## 6. Role & access implications (read before implementing — easy to get wrong)

- The self-service registrant's `TenantUser.role = 'super'` **inside their own new tenant only**.
  This does **not** grant landlord access: `app/Http/Middleware/EnsureLandlord.php` hard-codes
  its check to the tenant with id `'swecom'` specifically (pre-existing, documented tech debt —
  see that file), so a `super` role in `tenant_acme` never passes it. No change needed to
  `EnsureLandlord` for this to stay safe — just don't "fix" that hard-coding as part of this
  work without separately deciding how multi-landlord access should work (out of scope here).
- `ResolveTenant`/`ResolveTenantWeb` need one new check: after resolving the tenant via
  `TenantUserIndex`, also load the `Tenant` and check `registration_status`. If `'pending'` or
  `'rejected'`, do NOT initialize tenancy for normal app routes — redirect (web) / 403 (api) to
  the pending/rejected holding state instead. The one exception: the holding page itself, and
  the Landlord routes (which don't initialize tenancy for the *acting* tenant at all — they
  operate centrally, per `EnsureLandlord`'s existing design) must remain reachable regardless of
  the requester's own tenant's approval status.

## 7. Google OAuth

Use `laravel/socialite` (not installed yet — `composer require laravel/socialite`). Standard
flow: `Socialite::driver('google')->redirect()` / `->user()`. Match the Google account to an
existing central `User` by email if one exists (log them in, skip to workspace-creation only if
they don't already own/belong to a tenant); otherwise create a new central `User` with a random
unusable password (`Hash::make(Str::random(40))`) and `email_verified_at` set immediately
(Google already verified it). Needs `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/
`GOOGLE_REDIRECT_URI` env vars — document them in `.env.example` (don't invent placeholder
values that look like real credentials; use obvious placeholders and note in the PR/report that
real OAuth credentials must be provisioned in Google Cloud Console before this works end-to-end
in production — that's outside what any agent can do from this environment).

## 8. Explicitly out of scope for this pass

- Multi-workspace-per-user (switching between several tenants you belong to) — a self-service
  user gets exactly one workspace for now.
- Billing/subscription for workspaces — not asked for.
- Email verification flow for classic (non-Google) signups beyond what Laravel's default
  `MustVerifyEmail` scaffolding would need — if it adds meaningful scope, note it as a follow-up
  rather than building it now.
