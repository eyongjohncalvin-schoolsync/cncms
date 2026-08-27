---
name: cncms-context
description: >
  Complete project context for the Cable Network Management System (CNCMS) built for SWECOM PLC,
  a cable TV operator in Kumba 3, Cameroon. Use this skill whenever the user asks about CNCMS,
  ShalomTech, SWECOM, cable subscriptions, manuscripts, billing, zones, payments, customer management,
  or the new Resources Management module. Also trigger for any feature development, database queries,
  UI design, business logic, or bug fixes related to this system. This skill contains the full schema,
  business rules, data volumes, and the spec for the planned Resources Management module.
---

# CNCMS — Cable Network Management System

## System Identity

**Product name:** CNCMS (ShalomTech branding)
**Operator:** SWECOM PLC
**Location:** 3/Corners, Kumba 3, South West Region, Cameroon
**Stack:** Laravel 13 (PHP 8.3) · PostgreSQL 16 · Stancl Tenancy (schema-per-tenant, single physical DB) · React + TypeScript + Inertia.js (web) · React Native/Expo (mobile, design stage — see `references/mobile-app-react-native.md`) · RESTful API (Sanctum) · runs locally at `127.0.0.1:8000`
**Payment channel:** MTN/Orange Mobile Money (MOMO) — numbers `676876509 / 672528022`
**Reconnection fine:** 2,000 FCFA for late payers
**Currency:** FCFA (Central African Franc)

The app manages cable TV subscriptions for ~549 customers spread across 29 geographic zones
in Kumba 3. Its core loop is: record payments → run monthly billing calculation → print bills
→ generate manuscript register → notify customers.

### Architecture Evolution (v2)

The system has migrated from a monolithic Laravel/MariaDB setup to a modern, scalable
architecture designed to support field agents via mobile apps, multi-tenancy for future
expansion, and robust financial auditing. Key changes:

| Aspect | v1 (Legacy) | v2 (Current) |
|---|---|---|
| Database | MariaDB 10.4 | PostgreSQL 16 |
| Primary keys | Auto-increment INT | UUID (external) + bigserial (internal) |
| Frontend | Blade only | Inertia.js/React (web admin) + React Native/Expo (mobile agent, design stage) |
| API | None | RESTful (Laravel Sanctum) |
| Multi-tenancy | None | Stancl tenancy (schema-per-tenant, single physical DB) |
| Offline support | None | SQLite local cache + sync queue |
| Audit trail | Basic activity log | Comprehensive event-sourced audit |
| Payment verification | None | Receipt-based verification + MOMO cross-check |
| Financial precision | FLOAT | DECIMAL(12,2) |

---

## Modules Overview

| Module | Status | Purpose |
|---|---|---|
| Zone Management | Live | Define and upload geographic zones |
| Customer Management | Live | Register, edit, suspend/disconnect customers |
| Payment Recording | Live | Log all subscription payments with verification |
| Payment Verification | **New** | Receipt capture, MOMO cross-check, approval workflow |
| Manuscript Calculation | Live | Monthly arrears/credit/total-bill engine |
| Bill Print | Live | Per-customer printable bill slips |
| Manuscript Report | Live | Full monthly billing register (PDF/ZIP) |
| SMS Messaging | Live | Customer notification via messages table |
| Agent Management | Live | Field agents linked to zones |
| User & Role System | Live | 5-role access control + Sanctum tokens |
| Uploads | Live | Bulk import via xlsx (zones, customers) |
| Offline Sync | **New** | SQLite local-first + server sync for field agents |
| Audit Trail | **New** | Event-sourced audit logging for all mutations |
| **Resources Management** | **Planned** | Day-to-day expenditure tracking + P&L |

---

## Database — Quick Reference

See `references/database-schema.md` for full column lists, DDL, and sample data.

### ID Strategy: Dual-Key Pattern

Every tenant-aware table uses a **dual primary key** pattern:

- **`id` (bigserial)** — Internal auto-incrementing key. Used for JOINs, FKs,
  and internal queries. Fast, sequential, index-friendly. Never exposed externally.
- **`uuid` (UUID v7)** — External-facing identifier. Used in API URLs, mobile sync
  payloads, receipt codes, and customer-facing references. UUID v7 is time-ordered
  (sortable), has built-in randomness for security, and prevents enumeration attacks.

**Rule:** All API responses return `uuid`, never `id`. All internal queries use `id`
for performance. Mobile apps store `uuid` for offline references.

### Core tables

**`zones`** — 29 zones, all town = `KUMBA 3`. Zone names follow a coded pattern:
`THR01 (3/CORNERS)`, `AR01(JUNCTION)`, `M R01 (DODO)`, `LR R01 (LADUMA ROAD)`, `T R01`, etc.
Zones are bulk-imported via `zone_upload.xlsx` (two columns: `name`, `town`).

**`customers`** — ~549 records. Key fields: `zone_id`, `name`, `phone`, `bill` (monthly rate),
`others` (initial carried-over balance), `level` (normal/Vip/Operator), `status`
(active/passive/disconnected/suspended). Bill rates: 2,000–12,500 FCFA/month. Bulk-imported
via `customer_upload_main.xlsx`.

**`payments`** — 2,591 records. Key fields: `customer_id` (FK by UUID), `amount` (DECIMAL),
`frequency` (monthly/yearly/months), `months` (N for multi-month), `expiration_date`,
`credit` (DECIMAL), `processed_at`, `verification_status` (new: pending/verified/rejected).
Multi-month payments set `expiration_date` N months forward and freeze the customer from billing
until that date.

**`payment_verifications`** — **New table.** Links payments to receipt evidence and
MOMO transaction confirmations. Fields: `payment_id`, `receipt_photo_path`, `momo_ref`,
`momo_status` (confirmed/failed/pending), `verified_by`, `verified_at`, `notes`.

**`manuscripts`** — 521 records (one per active customer). Key computed fields:
`bill`, `total_arrears`, `credit`, `total_bill`, `payment_expiration`. Updated monthly by
`manuscript:calculate`. Formula: `total_bill = bill + total_arrears - credit`.

**`messages`** — SMS log per customer. Fields: `customer_id`, `content`, `sid`, `status`, `type`.

**`uploads`** — File import queue. Status: `pending -> processing -> completed -> failed`.

**`users`** — 6 system users. Roles: `super`, `admin`, `manager`, `agent`, `worker`.
Real owner: user ID 6 (`Ebaieyong Kelvin Mekume`, username `miskhan`).
Each user also has a Sanctum token for API access.

**`agents`** — Field collectors linked to `zone_id`. Fields include salary, photo, marital status.
Agents now have a `sync_token` for offline data sync and a `last_sync_at` timestamp.

**`command_runs`** — Audit log of `manuscript:calculate` executions. 14 runs recorded
from May 2025 to July 2026.

**`alerts`** — Simple notification store (name, message).

**`companies`** — Single record: SWECOM PLC, with MOMO numbers, tech contact, logo.

**`audit_logs`** — **New table.** Comprehensive event-sourced audit trail. Records every
CREATE, UPDATE, DELETE across all tenant tables. Fields: `id`, `tenant_id`, `table_name`,
`record_uuid`, `action`, `old_values` (JSONB), `new_values` (JSONB), `user_id`, `ip_address`,
`user_agent`, `created_at`. Immutable — no deletes allowed.

**`sync_queue`** — **New table.** Outbox/inbox queue for offline sync between mobile
apps and server. Fields: `id`, `device_id`, `direction` (up/down), `entity_type`,
`entity_uuid`, `payload` (JSONB), `status` (pending/synced/conflict/failed),
`attempted_at`, `completed_at`, `error_message`.

---

## Business Rules

Read `references/business-rules.md` for the full billing logic. Key points:

1. **Monthly bill** is the customer's fixed rate (`customers.bill`). It never changes unless
   edited by an admin.

2. **`manuscript:calculate`** runs at month-end via Laravel Artisan. It:
   - Sums all `payments.amount` for the customer since last run
   - Compares total paid vs total owed over all time
   - Writes `total_arrears`, `credit`, and `total_bill` to `manuscripts`
   - Sets `payment_expiration` for multi-month prepaid customers
   - Skips payments with `verification_status = 'rejected'`

3. **Arrears** accumulate month over month. A customer who hasn't paid in 3 months
   at 2,500 FCFA/month will show `total_arrears = 7,500` and `total_bill = 10,000`.

4. **Credit** is an advance/overpayment balance. If a customer pays 2 months ahead,
   `credit = 2500` and `total_bill = 0`. Credit is consumed before arrears.

5. **`others`** on the customer record is the seeded initial balance loaded on import.
   It is applied once during the first manuscript calculation.

6. **Disconnected customers** still appear in manuscripts with their arrears frozen.
   They do not accrue new monthly charges once disconnected. Reconnection requires
   paying the outstanding balance plus the 2,000 FCFA reconnection fine.

7. **Bill print deadline** is the 5th of every month.

8. **Payment frequency rules:**
   - `monthly` — processed every month, no expiration date
   - `months` — N months prepaid; `expiration_date = NOW + N months`; not billed until expiry
   - `yearly` — `expiration_date = NOW + 12 months`

9. **Payment verification (new):**
   - Payments recorded offline or by agents enter with `verification_status = 'pending'`
   - Admin/manager can approve (attach receipt photo + optional MOMO reference) or reject
   - Only `verified` payments are processed by `manuscript:calculate`
   - `rejected` payments are excluded from billing and flagged for review
   - See `references/audit-strategy.md` for the full verification workflow

10. **Offline sync rules (new):**
    - Field agents record payments and expenses offline using a local SQLite database
    - When connectivity is restored, the app pushes queued changes to the server
    - Server assigns UUIDs for new records and returns them to the client
    - Conflict resolution: server wins for the same entity; new entities are merged
    - See `references/offline-sync-strategy.md` for the full sync protocol

---

## Bulk Import Formats

### Zone upload (`zone_upload.xlsx`)

| Column | Required | Notes |
|---|---|---|
| name | Yes | Must match zone names exactly elsewhere |
| town | Yes | Usually "KUMBA 3" |

### Customer upload (`customer_upload_main.xlsx`)

| Column | Maps to | Notes |
|---|---|---|
| name | customers.name | |
| phone | customers.phone | 78% of current records have no phone |
| level | customers.level | normal / Vip / Operator |
| location | customers.location | |
| zone | customers.zone_id | Looked up by zone name |
| bill | customers.bill | Monthly rate in FCFA |
| others | customers.others | Pre-existing balance to seed |
| arrears | *(read-only)* | Computed — leave blank on import |
| Credit | *(read-only)* | Computed — leave blank on import |
| Total Bill | *(read-only)* | Computed — leave blank on import |
| status | customers.status | Active / Disconnected |

**Known data quality issues to watch for:**
- Placeholder rows (`empty 1`, `empty 2`, etc.) — delete before importing
- "TOTAL THREE CORNERS" — stray subtotal label, not a customer
- Phone numbers in multiple formats (`677440670`, `(67) 321-7927`, `6740774444`)
- ~78% missing phones — SMS features won't work for most customers

---

## Insights from Similar Systems

These patterns from comparable cable/ISP billing systems inform feature decisions:

- **Credit limit management** — assign per-customer credit tolerance before auto-disconnect.
  Currently CNCMS triggers disconnection manually; an auto-threshold per zone or level would
  reduce agent workload.
- **STB (Set-Top Box) tracking** — most cable systems track device serials per subscriber.
  Not currently in CNCMS but common ask as network grows.
- **Collection receipts** — agents need printable per-payment receipts in the field, not just
  monthly bill slips. The new payment verification module addresses this.
- **Cash collection summary per agent** — agents need a daily/weekly totals report grouped by
  zone they cover.
- **P&L visibility** — operators need income (total collections) vs. expenses (salaries, fuel,
  maintenance) on a monthly basis to understand margin. This is the driver for the Resources module.
- **Churn tracking** — disconnected-to-active reconnection rate is a key health metric for LCOs
  (Local Cable Operators) like SWECOM.
- **Mobile-first field ops** — successful cable operators in emerging markets equip field agents
  with offline-capable mobile apps for payment collection, disconnection tracking, and real-time
  reconciliation. This is the primary driver for the mobile/offline architecture.

---

## Planned Module: Resources Management

### Purpose

Track all of SWECOM PLC's day-to-day operational expenditures alongside subscription income,
giving the owner a clear monthly P&L view without needing a separate accounting tool.

### Expense categories (suggested)

| Category | Examples |
|---|---|
| Staff & Labour | Agent salaries, technician day wages, bonus payments |
| Field Operations | Fuel, transport fares for agent routes |
| Network Maintenance | Cable repairs, connector materials, tools |
| Office & Admin | Stationery, printing, airtime/data for staff |
| Utilities | Electricity bill for office/head-end |
| MOMO Fees | Transaction fees on mobile money collections |
| Broadcaster Fees | Signal/content licensing fees (if applicable) |
| Equipment | New decoders, splitters, cables purchased |
| Miscellaneous | Any one-off costs that don't fit above |

### Key features to build

1. **Expenditure entry form** — date, category, amount, description, optional receipt photo upload.
   Agents and above can record; only admin/super can edit or delete. Mobile-optimised.

2. **Daily summary view** — list of today's entries with running total. Quick-entry
   flow optimised for mobile use in the field.

3. **Monthly P&L dashboard** — compares:
   - Income: sum of `payments.amount` for the period (only verified payments)
   - Expenses: sum of `expenditures.amount` for the period
   - Net: Income - Expenses, with % margin

4. **Category breakdown** — pie/bar chart of expenses by category for the month.

5. **Budget vs actual** — if budgets are set, show variance per category.

6. **Export** — monthly expense report as PDF or Excel alongside the existing manuscript.

7. **Offline support** — agents can record expenditures offline and sync when connected.

### Integration points with existing system

- The `payments` table already holds all income data — the P&L queries only `verified` payments.
- Use `command_runs` pattern to log monthly expense summary generation.
- All expenditure mutations are captured in `audit_logs` for full traceability.
- The `companies` table holds the company context shown on reports.
- Expense reports should use the same header style as the Bill Print (company logo, MOMO numbers, contact).
- Expenditures sync via the same `sync_queue` mechanism as payments.

---

## Multi-Tenancy (Stancl Tenancy)

CNCMS uses **Stancl/tenancy** in **schema-per-tenant** mode (single physical PostgreSQL
database, one Postgres schema per tenant) to prepare for future expansion when SWECOM may
manage cable networks in additional towns or when ShalomTech onboards other LCO clients —
without paying the operational cost of database-per-tenant (per-tenant connection pools,
cross-database joins) while a single physical database still provides real, engine-level
isolation per tenant.

### Tenant model

- **Single physical database:** `cncms` (PostgreSQL). Stancl's `PostgreSQLSchemaManager` gives
  each tenant its own Postgres schema inside that one database (e.g. `tenant_swecom`), rather
  than a separate database.
- **Central/`public` schema:** stores the `tenants` table, `domains`, the global `users` table,
  `personal_access_tokens` (Sanctum), and platform-level settings.
- **Tenant schema:** customers, payments, manuscripts, expenditures, agents, zones, messages,
  audit_logs, and every other domain table live inside the tenant's own schema, set via Postgres
  `search_path` when tenancy is initialized.
- **Shared data:** `users` is central (`public` schema) — not duplicated per-tenant. Role
  assignment is per-tenant via a `tenant_users` pivot table that lives inside each tenant's
  schema (referencing the central `users.id`). Because everything is one database, joining
  `public.users` to `tenant_swecom.tenant_users` is an ordinary cross-schema join, not a
  cross-database query.
- **Why central users:** Sanctum's `personal_access_tokens` table must live alongside `users`, so
  a single central table avoids a "which schema do I check this token against" problem on every
  authenticated request — also required for the mobile agent app, which hits one API URL with no
  subdomain/tenant context; tenancy is resolved server-side from the authenticated user's
  `tenant_users` membership, not from the request domain.

### Tenant provisioning

```bash
php artisan tenants:migrate --force
```

Runs all tenant migrations (`database/migrations/tenant/`) against every provisioned tenant
schema. Central migrations (`database/migrations/`) run via the normal `php artisan migrate`.

### Current setup

Two real tenants exist today: `swecom` (SWECOM PLC) and `multimedia-digital-cable-network`.
Both run in the same physical database, in their own schemas.

---

## Frontend Architecture

CNCMS v2 ships **two first-class frontends** that share a single RESTful API backend.

### Web Admin Panel (Inertia.js + React + TypeScript)

Full-featured admin dashboard used by super admins, admins, and managers in the office.
Built with Laravel Inertia.js (SSR-first, SPA feel) + React 18 + TypeScript + Tailwind CSS.

**Used by:** super, admin, manager, agent (view-only), worker (limited)

**Key pages:**
- Dashboard — KPIs (total customers, collection rate, active agents, pending verifications)
- Customers — CRUD, zone filtering, status management, bulk import
- Payments — record, list, verify/reject workflow, receipt upload
- Manuscripts — monthly billing view, print bills, export manuscript (ZIP/PDF)
- Agents — manage field agents, assign zones, view sync status
- Resources — P&L dashboard, expenditure entry, category management, export
- Audit Logs — filterable event viewer (admin only)
- Settings — company info, user management, role assignment

**Auth:** Laravel Sanctum SPA mode (cookie-based). Users log in via the web and get a
session cookie. Inertia.js handles routing server-side — no separate API calls needed
for web pages.

**Responsive:** The web panel is fully responsive for tablet use in the office, but
is NOT designed for offline field use (that is the mobile app's job).

### Mobile Agent App (React Native + Expo) — design stage, not yet built

Offline-first field app used by agents to collect payments and record expenditures
while walking their zones. Built with React Native (Expo, EAS Build), Android-first.
Not a Capacitor WebView wrapper — React Native has no DOM, so the UI layer is a native
rewrite; only TypeScript API-shape interfaces are shared with the web app, not components.
Full design in `references/mobile-app-react-native.md`.

**Used by:** agent (primary), manager (supervisory view)

**Key screens:**
- Home — today's collection summary, pending verifications, sync status
- Customers — cached list for zone, search by name/phone
- Record Payment — quick-entry form (customer, amount, frequency, receipt photo)
- Record Expense — quick-entry form (category, amount, description, receipt photo)
- Payment History — list of own recorded payments with verification status
- Sync Status — pending uploads, last sync time, manual sync trigger

**Auth:** Laravel Sanctum token-based (Bearer token). Agent logs in once; token is
stored securely on-device and refreshed periodically.

**Offline:** Full offline capability via local SQLite database. See
`references/offline-sync-strategy.md` for the complete protocol.

### Shared layer

Both frontends share:
- The same RESTful API endpoints (web uses Inertia server-side rendering,
  mobile calls endpoints directly via Axios)
- The same React + TypeScript component library (Tailwind CSS + headless UI components)
- The same Sanctum authentication system
- The same permission model and role-based access control

---

## API Architecture

See `references/api-spec.md` for the full endpoint listing.

The API serves **both** the web admin panel (via Inertia.js server-side adapters)
and the mobile agent app (via direct HTTP calls). There is one unified API —
no separate web-only or mobile-only endpoints.

### Authentication

- **Web admin:** Laravel Sanctum SPA mode (cookie-based, managed by Inertia.js middleware)
- **Mobile agents:** Laravel Sanctum token-based (Bearer token in Authorization header)
- Tokens are issued on login and rotated periodically. Each agent has one active device token.

### Endpoint patterns

```
/api/v1/auth/login             — Login (both web and mobile)
/api/v1/auth/logout            — Logout
/api/v1/customers             — List (paginated, filterable by zone/status)
/api/v1/customers/{uuid}      — Show (by UUID, never by id)
/api/v1/payments               — List/Create
/api/v1/payments/{uuid}       — Show/Update
/api/v1/payments/{uuid}/verify — Verify (admin/manager)
/api/v1/payments/{uuid}/receipt — Upload receipt photo
/api/v1/manuscripts           — Current period
/api/v1/manuscripts/export     — Download ZIP/PDF
/api/v1/sync/push             — Offline sync push (mobile only)
/api/v1/sync/pull             — Offline sync pull (mobile only)
/api/v1/sync/upload-receipt   — Upload receipt after sync (mobile only)
/api/v1/resources/expenditures — CRUD
/api/v1/resources/dashboard   — P&L data
/api/v1/resources/categories — Manage categories
/api/v1/resources/export/{period} — Download monthly report
/api/v1/audit/logs             — View audit trail (admin only)
/api/v1/bills/{uuid}/print     — Generate bill slip PDF
/api/v1/zones                 — List zones
```

All mutations are audit-logged. All responses use UUIDs for entity references.

---

## Development Notes

- All FCFA amounts in new tables use `DECIMAL(12,2)` — never `FLOAT`. Existing `payments`
  and `manuscripts` tables should be migrated from `FLOAT` to `DECIMAL(12,2)` as part of v2.
- UUID v7 is generated server-side for all new records. Mobile apps generate temporary
  local UUIDs (v4) for offline-created records, which are replaced by server-issued UUID v7
  on sync confirmation.
- Zone names must match exactly between upload files and the `zones` table — any mismatch
  will cause import failures (foreign key constraint on `zone_id`).
- The `manuscript:calculate` command tracks its own runs in `command_runs` — follow the same
  pattern for any new scheduled tasks.
- Laravel Artisan commands follow the pattern `namespace:action` (e.g. `manuscript:calculate`,
  `expenditure:monthly-summary`).
- The web admin panel uses Inertia.js + React + TypeScript (SSR with SPA feel, no separate API needed for page loads).
  The mobile agent app (design stage) will use React Native + Expo and call the REST API
  directly for data operations, with local SQLite (`expo-sqlite`) for offline storage — see
  `references/mobile-app-react-native.md`.
- React packages: `@inertiajs/react`, `@headlessui/react`, `@tanstack/react-query` (server state),
  `zustand` (client state), `axios`, `recharts` (charting), `react-hot-toast` (notifications).
- PostgreSQL extensions enabled: `uuid-ossp` (for UUID generation), `pgcrypto` (for hashing),
  `btree_gin` (for JSONB indexing on audit_logs).

---

## Reference Files

- **Full schema (PostgreSQL DDL):** `references/database-schema.md`
- **Billing calculation logic:** `references/business-rules.md`
- **Offline sync strategy:** `references/offline-sync-strategy.md`
- **Audit trail design:** `references/audit-strategy.md`
- **RESTful API specification:** `references/api-spec.md`
- **Web admin panel design:** `references/web-admin-spec.md`
- **Company settings (logo, head office, RCCM/NIU):** `references/company-settings.md`
- **Multi-branch / multi-location support:** `references/branches-and-locations.md`
- **French/English language support:** `references/language-support.md`
- **Bill notifications (SMS/Email/WhatsApp):** `references/bill-notifications.md`
- **Frontend design system:** `references/frontend-design-system.md`
- **Self-service tenant onboarding:** `references/self-service-onboarding.md`
- **Fine-grained payment permissions (RBAC), including the Investor tier:** `references/rbac-permissions.md`
- **Mobile field-agent app (React Native):** `references/mobile-app-react-native.md`
- **Task scheduler (manuscript/bill scheduling, chunked jobs):** `references/task-scheduler.md`
- **In-app notification system:** `references/in-app-notifications.md`
- **Complaint Desk (web + mobile):** `references/complaint-desk.md`
- **Prepaid-time preservation across suspend/disconnect:** `references/prepaid-pause-handling.md`
- **Arrears Adjustment (write-off) — maker-checker workflow, where to find it in the UI:** `references/arrears-adjustment.md`
- **Backup & restore process (design, not yet implemented):** `references/backup-strategy.md`

Note: this file and `.ai/skills/cncms-context/SKILL.md` are kept byte-identical; a third,
older copy at `.ai/skills/cncms/cncms-context/` has drifted (different tenancy-model wording, a
stray incorrect operator line) and should be treated as superseded, not a second source of truth.
