# Mobile Field-Agent App — React Native Design Spec

Status: **Design, not yet implemented** | Owner decision (2026-08-25): the mobile agent app will
be built with **React Native (Expo)**, not Capacitor. This supersedes every other mention of
Capacitor in this skill's docs — treat any remaining "Capacitor" reference elsewhere as stale
until corrected. Built from a 5-expert brainstorm (architecture/stack, offline-sync/data-layer,
core field workflows, auth/permissions, UI/UX), deliberation-only — no code written yet.

---

## 1. Why React Native/Expo, and why this isn't a Capacitor port

Capacitor's whole value proposition was running the same web React/DOM components inside a
WebView. React Native has no DOM — essentially 100% of the UI layer is a rewrite regardless of
which native framework is chosen, so there's no "shared component library" to preserve from the
web app. What *is* shareable: a narrow set of TypeScript interfaces (API request/response shapes)
and maybe a couple of pure business-rule helpers — copy these by hand (~15 interfaces), don't
stand up a monorepo/shared-package for a surface this small.

**Expo (managed, EAS Build/Update), not bare React Native CLI.** Every native capability this app
needs — camera for receipts, secure token storage, background/foreground sync triggers, network
state — is a first-party Expo module; nothing here requires ejecting. EAS Build removes the need
for a local Android SDK/Xcode toolchain (relevant on this project's Windows dev environment). EAS
Update matters specifically for field agents: push a JS-only bug fix over the air, don't make an
agent manually reinstall an APK. **Android-first, iOS not a v1 priority** — Android's share of the
Cameroon market is dramatically higher than the global average; don't spend v1 budget on iOS.

**Repo structure:** same repository, new `mobile/` directory alongside `app/`/`resources/` — not
a separate repo. Backend and mobile evolve in lockstep (most mobile data-layer changes are
downstream of `SyncService`/API resource shape changes), and a separate repo would mean
cross-repo PRs for what's usually one logical change.

## 2. State/data layer — deliberately NOT a heavy offline-sync framework

**Do not adopt WatermelonDB, RxDB, or any general bidirectional-replication library.** The server
already has a working, bespoke sync protocol (`SyncService`, `sync_queue`, `/api/v1/sync/push`
and `/pull`) with every conflict-resolution decision already made ("server wins," no tombstones,
no CRDTs needed — see `references/offline-sync-strategy.md`). A general replication framework
exists to solve configurable conflict resolution and continuous reactive sync at a scale (tens of
thousands of rows) this app doesn't have (~549 customers total, low hundreds per agent's zone).
Adopting one would mean either rebuilding the already-working server protocol to match the
library's expected shape, or writing an adapter layer that ends up being *more* code than calling
the existing `push`/`pull` endpoints directly.

**Recommended stack:** `expo-sqlite` (WAL mode) as the on-device store + a small hand-written
`SyncManager` module that speaks the server's actual protocol directly + **TanStack Query** as the
UI-facing cache/loading-state layer sitting on top (screens query SQLite, which is the real
source of truth and always available offline; mutations write to SQLite first, `SyncManager`
picks them up asynchronously).

### Local schema (SQLite)

- **`customers`** — read-only cache, fully replaced/merged from `pull()`'s `changes.customers`
  only, never locally edited. `uuid`, `name`, `phone`, `bill`, `location`, `level`, `status`,
  `zone_uuid`, `cached_at`.
- **`payments`** — doubles as the outbox and the synced-history view: `local_uuid` (PK,
  client-generated UUIDv4 — see idempotency note below), `server_uuid` (nullable until synced),
  `customer_uuid`, `amount`, `credit`, `frequency`, `months`, `verification_status`,
  `rejection_reason`, `sync_status` (`queued|syncing|synced|failed`), `sync_error`, timestamps.
  Reconciliation on `pull()` matches by `server_uuid` — a queued item that hasn't completed its
  push round-trip yet can't be touched by a later pull. The local `created_at`/timestamp on this
  row is sent to the server on push as the wire field `created_at` (per `SyncPushRequest`'s
  `changes.payments.*.created_at` rule) — server-side it is stored as the separate
  `payments.collected_at` column, NOT the payment's own server-side `created_at` (which keeps
  meaning "when this row landed on the server" — see `App\Services\SyncService::pushPayment()`'s
  doc comment). No mobile-side change is required for this; it's a server-side naming/storage
  detail only.
- **`expenditures`** — mirrors `payments` (`local_uuid`, `server_uuid`, `category_uuid`, `amount`,
  `description`, `spent_at`, `notes`, `receipt_local_uri`, `receipt_server_path`, `sync_status`).
- **`expense_categories`** — populated from the plain `GET /api/v1/categories` REST endpoint, NOT
  from `pull()` (confirmed: `pull()` only returns `customers` and `payments`). Refresh
  opportunistically (once per session); no sync-queue machinery needed for this near-static list.
- **`sync_meta`** (KV) — `last_sync_at`, `device_id` (generated once, persisted, sent as
  `sync_queue.device_id` and stored server-side on `agents.sync_token`), `agent_zone_uuid` (cached
  for UI filtering only, never trusted for authorization).

### Sync triggers

App-foreground (`AppState` → active), network-reconnect (`netinfo` listener), immediately after
each local write (if online), manual pull-to-refresh, and a periodic in-foreground timer
(5-10 min) while the app stays open. **No OS-level background sync (Background Fetch/WorkManager)
for v1** — real native complexity for marginal gain given agents open the app repeatedly through
the day anyway; the durable local outbox already makes "sync eventually happens" correct
regardless of what triggers it.

### Conflict/rejection UI — two distinct cases, not one generic "sync failed"

1. **Immediate push failure** (validation rejected at push time, e.g. bad customer UUID) —
   surface inline in the outbox/queue view next to the item, with retry.
2. **Delayed rejection** (discovered on a later `pull()`, i.e. an office reviewer rejected a
   payment) — needs a **persistent** notification, not a toast, since the agent has likely moved on
   to other customers by the time this surfaces. Needs the rejection reason
   (`Payment.verification.notes`) and a clear next step. Per the field-workflow brainstorm: **no
   in-app resubmit/edit flow in v1** — purely informational, with a tap-to-call-the-office
   affordance; correction stays an office-only workflow (`PaymentPolicy::update`/`delete` are
   already office-only).
   Note this can only happen to a payment that landed `pending` in the first place — per the
   2026-08 auto-verify revision (`App\Services\PaymentService::create()`; business-rules.md §5),
   `verification_status` is decided purely by whether the creating actor could also verify this
   exact payment, not by channel: super/admin/manager auto-verify unconditionally, an agent
   auto-verifies for a customer in their own zone, and only an agent outside their zone (or a
   worker, always) lands `pending`. So a synced payment does NOT universally start `pending` —
   this delayed-rejection path only ever applies to that pending subset.

## 3. A real backend gap found during this brainstorm — not yet fixed

**No idempotency exists in the current sync-push implementation.** `payments`/`expenditures` have
no `local_uuid` column, and `pushPayment()`/`pushExpenditure()` create a row unconditionally on
every call — there's no check for "did I already apply this local_uuid." If a push succeeds
server-side but the response never reaches the client (a dropped connection right after the
server commits — a routine occurrence on flaky field connectivity), the client's retry will
create a **second** payment row. This is a real correctness gap, not hypothetical, and needs a
small surgical server-side fix before the mobile app can safely ship:

- Add a nullable, unique `local_uuid` column to `payments` and `expenditures`.
- In `pushPayment()`/`pushExpenditure()`, check for an existing row with that `local_uuid` before
  calling `create()` — if found, treat as already-synced and return the existing `server_uuid`
  with `status: 'synced'` rather than re-creating.

Also confirmed during this brainstorm: `offline-sync-strategy.md`'s claimed "duplicate detection
(same customer/amount/date within 1h)" and "device-revocation cancels pending syncs" behaviors are
**not actually implemented** in the real `SyncService` code — treat that doc as a north star for
shape, not as ground truth for current behavior, until it's corrected.

## 4. Core screens (v1 scope)

Bottom tab bar, 4 tabs (not a drawer — this is a one-handed, walking-around, glanceable tool):

- **Home** — sync-status strip (see §5, always visible, not just here), today's collection total
  (local-only, instant), a 3-tile zone snapshot (arrears count/total, disconnected-but-visitable
  count), a "Continue your route" shortcut into Customers pre-filtered to "owes money."
- **Customers** — cached, scoped to the agent's own zone. List row: name, status-color dot,
  arrears amount (bold, right-aligned — this is what the agent is walking the zone to collect),
  phone with tap-to-call. Search by name/phone; filter chips (All / Owes money / Paid up /
  Disconnected), defaulting to "Owes money" when arrived via the Home shortcut. Tapping a row →
  Customer Detail with a **Record Payment** button (becomes **Reconnect & Pay** — system-computed
  arrears, read-only, if the customer is disconnected/suspended — plus an "Include reconnection
  fine" toggle, unchecked by default per the 2026-08 owner decision that the 2,000 FCFA fine is
  admin/office discretion, not automatic, for either status; this maps directly to the existing
  `CustomerStatusService::reconnect()` server logic and its `$includeFine` parameter, it isn't a
  mobile-only concept).
- **Record Payment** — also its own tab (not nested-only), since it's the single highest-frequency
  action. Customer context card (name, zone, current bill/arrears) pinned at top, non-editable.
  Amount field is the dominant visual element, large numeric keypad, pre-filled with a tap-to-accept
  "Use bill amount" chip. Frequency as a segmented control (Monthly/Multi-month/Yearly), months
  field conditional. Receipt photo optional, camera-only (no gallery picker). Minimum required
  input: customer + amount + frequency — matches `StorePaymentRequest`'s actual validation, no
  mobile-only stricter requirement invented.
  **Mirrors the web Record Payment page's non-binding calculation guide** ("Reference only — not
  filled in for you") as a collapsible card above the amount field — collapsed by default showing
  current bill/arrears, expandable for last-payment detail and the per-frequency guide. Never
  auto-fills the amount input — this constraint applies at least as strongly on mobile as web,
  arguably more, since field cash handling is exactly the scenario the rule exists for.
- **History** ("My Recorded Payments") — this agent's own payments, newest first, with a
  verification-status badge (pending/verified/rejected). Rejected rows show the reason if
  recorded, informational only — no edit/resubmit affordance in v1 (see §2's conflict-UI note).

**Not top-level tabs, reached one tap deeper:** Record Expense (secondary CTA next to Record
Payment on Home — simpler form: category with icon picker, amount, description (required — the
only field agents leave behind as a paper trail for office reconciliation), date defaulting to
today, optional receipt photo). Sync Status is not a tab at all — it's the persistent strip (§5)
with a tap-through to a lightweight detail sheet (pending item list, per-item error detail,
manual "Sync Now" button).

### Explicitly out of v1 scope

Customer self-service, full audit-log browsing, on-device charts/reporting (that's `/reports` and
`/resources` on web), customer create/edit, payment editing/deletion from mobile ever (matches
`PaymentPolicy::update`/`delete` being office-only), rejected-payment resubmission, multi-zone
agent support (v1 assumes one agent = one zone, matching `Agent.zone_id` being a single FK),
in-app messaging/SMS composition, bulk payment entry (doesn't map to one agent standing in front
of one customer — every mobile payment is inherently single), and any dedicated verify/reject
approval UI on mobile. Zone-scoped auto-verify over sync has since shipped (2026-08 revision,
`App\Services\PaymentService::create()`): an agent's own-zone customer now auto-verifies
immediately at creation, whether recorded live or synced from offline, with no separate
approve/reject action needed for that case — see business-rules.md §5's auto-verify table. What
remains out of v1 scope is a full manual verify/reject review UI on mobile for OTHER
agents'/zones' still-pending payments (that stays an office/web-only workflow, matching
`PaymentPolicy::verify()`).

## 5. The one design principle everything else serves: offline status must never look like an error

A persistent, unobtrusive status strip (~28-32dp) below the header, visible on every screen — not
buried in a menu. Four states, deliberately differentiated in tone, not just icon/color:

| State | Treatment |
|---|---|
| Online, fully synced | Nearly invisible — small solid dot, calm |
| **Offline, queuing (NORMAL operation)** | Visible but calm/neutral — amber, cloud-icon, never red. "Offline — 4 payments saved, will sync when connected." |
| Online, actively syncing | Determinate count ("Syncing 3 of 4…"), not an ambiguous spinner |
| Sync error on a specific item | Actionable-attention tone, tap-through to detail — never a blocking modal |

**Offline+queuing must never use the same red/alarm treatment as an actual sync error.**
Conflating "this is expected" with "something is wrong" is the fastest way to erode agent trust —
they'll assume the app is broken during entirely normal outdoor connectivity gaps, and either stop
trusting it or start double-recording on paper "just in case," which defeats the app's purpose.
The Record Payment confirmation state follows the same rule: a queued-offline payment gets a
distinct amber "Saved · will sync" badge, never green; green is reserved exclusively for
confirmed-on-server. Never let the two look similar enough to be confused at a glance.

## 6. Visual/interaction principles

- **56dp touch targets for primary actions** (48dp floor for everything else), **AAA contrast
  (7:1) as the working minimum** for body text, not AA — gray-on-white that reads fine indoors
  washes out in direct sunlight.
- **No gradients, no backdrop-blur/glassmorphism, no charts anywhere** — all look heavy/muddy in
  bright light or cost real battery/frame-rate on modest Android hardware for a full outdoor
  shift; large plain numerals communicate faster than a chart in this context anyway. This
  deliberately drops several patterns the web app uses (gradient icon-chips, blur headers) — brand
  continuity comes from reusing the *accent-color-per-feature-area* concept (`NAV_ACCENTS`), not
  from copying literal web visual effects that don't survive the transition.
- **No full component library** (React Native Paper commits to Material Design's look, breaking
  continuity with the web app's distinct branding; Tamagui's build-time value only pays off past
  ~10-12 screens). Build ~8-10 primitives by hand instead, porting the web `StatCard`'s API
  directly for conceptual continuity.
- **Dark mode deliberately deferred for v1** — this is one of the rare apps where dark mode
  actively works against the primary constraint (daytime outdoor legibility wants high luminance,
  not low), not just unprioritized.
- **Plain-language errors, always with a next step**: "Couldn't reach the server — this payment is
  saved and will sync when you're back online," never "Network request failed."

## 7. Auth

- **`expo-secure-store`** for the Sanctum Bearer token (`WHEN_UNLOCKED_THIS_DEVICE_ONLY` — prevents
  the token surviving into a cloud backup restored on a different device). Login →
  `POST /api/v1/auth/login` → immediately follow with `GET /api/v1/auth/me` for the *authoritative*
  role (the login response's `role` field is documented as display-only, not to be used for
  permission logic). No tenant-picker screen — confirmed via `ResolveTenant`'s own docs that
  cross-tenant membership isn't reachable via any UI path today.
- **No tenant-picker, no OAuth-style silent-refresh flow** — Sanctum tokens here are long-lived,
  revocable strings, not short-lived JWTs; trying to "refresh" one offline is solving a problem
  that doesn't exist. Treat any 401 as "token invalid, re-authenticate," never as "expired, refresh
  silently." Recommend the token gain a long-but-bounded expiration (e.g. 30 days, currently `null`
  = never expires per `config/sanctum.php`) rather than staying indefinite, plus a local
  biometric/PIN step-up (works offline) before the verify/payment-submit actions if the last
  such check was more than a few hours ago — this is the actual answer to "stolen unlocked phone
  mid-shift," which a network-dependent token TTL can't address.
- **On a confirmed-invalid token: never wipe local data.** An agent's offline-recorded payments
  represent real cash already collected from real customers — destroying that data because auth
  had a bad day is a business-risk-level bug. Correct behavior: block with a re-authenticate
  screen, keep all local SQLite data (including pending sync rows) fully intact, resume normally
  once logged back in. Only an explicit "log out and switch agent" action should ever clear local
  data, and even then it should warn/require confirmation if unsynced rows exist.
  **`offline-sync-strategy.md`'s current line — "if a device token is revoked, all pending syncs
  for that device are cancelled" — reads as "discard the unsynced data" if implemented literally
  and should be revised, not treated as settled,** per this same principle. Recovering a revoked
  agent's last unsynced day of collections is a real, currently-unsolved gap (needs an
  admin-authorized recovery path) — flagged for whoever builds the revocation flow, not solved by
  the mobile client alone.
- **Confirmed, not just assumed:** since the RBAC design that shipped is role+zone (every agent
  gets `verify`, fenced by zone — not a per-user grant), a plain `role === 'agent'` conditional in
  the mobile UI to show/hide the Verify action is correct. The "latent bug" concern raised earlier
  in this session (a literal role check missing a per-user override) applied only to the shelved
  full-permission-matrix design, not to what actually shipped.
- **Known gap, backend work, not mobile's to fix:** token issuance doesn't enforce single-device
  today (`AuthController::login()` never revokes prior tokens), and `SyncService::registerDevice()`
  is explicitly "first-sync-wins, no revocation logic" — contradicts the docs' "one active device
  token per agent" claim. Low-urgency at current scale (~6 users, one tenant) but worth an admin
  UI to list/revoke a specific agent's active tokens if agents ever start sharing/rotating devices.

## 8. UI/UX pass — 2026-08-27 (stage 1 of a 4-stage build)

Product owner asked for a genuinely-researched UX improvement pass on the four highest-frequency
screens (Home, Customers list/detail, Record Payment, Record Expense), informed by real comparable
apps rather than a cosmetic pass. Research pulled from: mobile-money AGENT apps (M-Pesa/MTN MoMo
agent apps — shallow, direct-to-task navigation; operational reliability over flashy UI), field-
service/route-based worker apps (thumb-zone placement — critical actions in the reachable bottom
third, destructive actions demoted/kept out of the primary zone, techs should see "the next action"
without hunting), and current offline-first sync-status UX writing (confirms this app's existing
queued/syncing/synced/failed vocabulary in §5 already matches 2025-2026 best practice — no changes
made there).

**Home (`app/(tabs)/index.tsx`):** The design doc's own §4 called for "a 3-tile zone snapshot
(arrears count/total, disconnected-but-visitable count)" that had never actually been built — Home
only showed a single "Customers cached" tile. Added `getZoneSnapshot()` (`src/db/customers.ts`, one
aggregate SQL query, local-only like every other Home stat) and wired it in. Deliberately laid out
as one full-width "Zone arrears outstanding" card (same large-numeral treatment as "Today's
collection" right above it — a paired "collected so far / still owed" read) plus two smaller tiles
(Disconnected, Customers cached) below, rather than 3 equal tiles — this is a deliberate deviation
from the doc's literal layout, in direct service of the product owner's explicit ask for "clearer
visual hierarchy for the arrears/collection-critical numbers." Both the arrears card and the
Disconnected tile are tappable straight into a pre-filtered Customers list (mirrors the mobile-
money-agent pattern of going directly to the task, not through a menu). Arrears card only renders
red/danger when `arrearsTotal > 0`, matching the existing red-only-when-owed convention already
used on the Customers list and Customer Detail — a fully paid-up zone doesn't get an alarming color
for a good, normal state.

**Customer Detail (`app/(tabs)/customers/[uuid].tsx`):** The Disconnect button (shipped just before
this pass) was a full 56dp solid-red button stacked directly under Record Payment — identical visual
weight to the single highest-frequency action in the app, for the rarest one. Field-service UX
research is consistent that destructive actions should be visually subordinate to and separated
from the primary action. Regrouped Disconnect and "Send Bill via WhatsApp" under a labeled "Other
actions" section, and gave Disconnect the new `dangerOutline` Button variant (outline, not solid
fill — see below) at default (48dp) rather than large (56dp) size. Disconnect's actual destination
(`app/disconnect/[uuid].tsx`, stage 2 territory) is untouched — this only changes how strongly the
entry point competes for attention, not what happens once tapped.

**Record Payment (`app/(tabs)/record-payment/index.tsx`) and Customers list
(`app/(tabs)/customers/index.tsx`):** Touch-target audit against this doc's own §6 (48dp floor).
Found three interactive elements under the floor: Record Payment's "Use bill amount" chip,
its collapsible reference-card header, and its "+ Add credit" disclosure link; and the Customers
list's filter chips. Fixed with `hitSlop={8}` on the two rarely-tapped Record Payment links
(same technique already used on that screen's "‹ Change customer" row — no visual change) and an
actual `minHeight: touchTarget.floor` resize on the Customers list filter chips and Record
Expense's date chips (tapped on nearly every use, so hitSlop alone felt like the wrong trade-off
there).

**Record Expense (`app/record-expense.tsx`):** Same touch-target fix as above for the Today/
Yesterday date chips. Also found the active-state date chip (white text on `colors.accent.expense`)
measures ~6.98:1 contrast — a hair under this app's own AAA 7:1 minimum (§6). Fixed by using a
slightly darker purple-800 (`#6B21A8`, ~8.7:1) *only* for that one white-on-fill pairing, scoped
locally to this file — deliberately did NOT change the shared `colors.accent.expense` token itself,
since that token is also used elsewhere on this same screen as a text/border color (e.g.
`categoryRowSelected`) where the contrast math is different and already passes; changing the shared
token would have been a wider, unjustified ripple for a narrow fix.

**Shared primitives touched (stage 2 should know about these — both are purely additive, nothing
existing changed behavior):**
- `src/components/ui/Button.tsx` — new `dangerOutline` variant (transparent fill, danger-colored
  1.5px border + text). Existing `danger` (solid fill) variant is untouched; anything already using
  it (emergency screen, disconnect/reconnect confirm screens) renders exactly as before.
- `src/components/ui/StatCard.tsx` — new optional `onPress` prop, passed straight through to the
  underlying `Card`. Omitted, a `StatCard` renders and behaves exactly as before (non-interactive).

**Dead-end/navigation audit:** Deliberately re-checked all five in-scope screens for the same class
of bug as record-payment's just-fixed "no way back to search" issue. Found no other dead ends —
Customer Detail has a real native back header (`app/(tabs)/customers/_layout.tsx`'s nested Stack),
Record Expense is a modal with a header back arrow plus its own post-save "Record another / Done"
choice, and every empty/not-found state already has an explicit way out (`EmptyState`'s
`actionLabel`/`onAction`). No new dead ends introduced by this pass either.

**Deliberately NOT changed:** `SyncStatusStrip`, the amber/green sync vocabulary, and the overall
information architecture (4 tabs, Record Payment as its own tab) — research confirmed these already
match current offline-first best practice, so this was "already good, don't fix it" territory, not
an oversight. Also did not touch History, Sync Status, Notifications, Complaint Desk, Reconnect,
Disconnect, or Emergency screens — out of stage 1's scope per the brief, left for stage 2.
