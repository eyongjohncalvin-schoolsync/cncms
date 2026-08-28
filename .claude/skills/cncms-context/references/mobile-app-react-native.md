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

## 9. UI/UX pass — 2026-08-27 (stage 2 of a 4-stage build)

Stage 2 covered everything stage 1 did NOT touch: `app/(tabs)/history.tsx`, `app/sync-status.tsx`,
`app/notifications.tsx`, `app/log-complaint.tsx`, `app/reconnect/[uuid].tsx`,
`app/disconnect/[uuid].tsx`, `app/emergency.tsx`. Research this pass: offline-capable apps'
confirmation-state/micro-interaction conventions (2025-2026 consensus — visible sync/pending/failed
indicators, no ambiguous spinners, optimistic-but-reversible updates for routine writes vs.
pessimistic/confirmed-only for money-moving ones); incident-tooling emergency/alert UX (PagerDuty/
Opsgenie's acknowledge-vs-dismiss separation and bulk-acknowledge precedent); empty/error-state
writing tone for field-worker (not consumer) apps; and banking-app transaction-confirmation-screen
conventions (dedicated in-app success view, not a native OS alert, for a completed monetary action).
This codebase already had Complaint Desk, Notifications, and the Emergency broadcast fully built
(not merely designed) going into this pass — `complaint-desk.md` §7's spec was already implemented
faithfully in `src/db/notifications.ts`, `src/notifications/notificationStore.ts`,
`src/utils/emergencyState.ts`, `src/components/ui/EmergencyBanner.tsx`, and `app/emergency.tsx`, so
most of this pass was real-gap fixes and one considered addition, not a rebuild.

**History (`app/(tabs)/history.tsx`):** Two fixes. (1) The status filter chips (All/Pending/
Verified/Rejected) measured ~34dp tall — under this doc's own §6 48dp floor, the exact same class of
bug stage 1 found and fixed on the Customers list's identical filter-chip pattern. Fixed identically
(`minHeight: touchTarget.floor` + `justifyContent: 'center'`), not with `hitSlop`, since these are
tapped on nearly every visit to this screen. (2) The screen only refetched on tab focus
(`useFocusEffect`), so a payment's `verification_status` flipping from pending to verified/rejected
via a background pull (the periodic in-foreground timer, §2) went unreflected if the agent was
already sitting on this tab when it happened — silently stale data is exactly what offline-first UX
research warns against ("users should always know what's going on"). Fixed by subscribing to
`subscribeSyncState` and refetching on every sync-state change, reusing the exact pattern
`app/sync-status.tsx` already established for its own live updates — not a new mechanism.

**Notifications (`app/notifications.tsx`):** Same live-staleness gap as History, same fix: the
screen only queried `getRecentNotifications()` on focus, so a new broadcast landing via
`SyncManager`'s pull cycle while the agent was already viewing this list wouldn't appear until they
left and came back. Now also subscribes to `notificationStore`'s `subscribeNotificationsState` (the
same store `SyncManager` already republishes to after every pull) and refetches on change.

**Log a Complaint (`app/log-complaint.tsx`):** Same touch-target audit as stage 1 ran on Record
Payment/Customers. Two findings: the Operational/Customer category chips measured ~43dp
(`paddingVertical: spacing.md` alone, no `minHeight`) — under the 48dp floor, and unlike the
description disclosure below it, these are the first control on the screen and are tapped on every
single submission, so fixed with an actual resize (`minHeight: touchTarget.floor`), matching stage
1's "resize what's tapped constantly, `hitSlop` what's tapped rarely" rule. The "+ Add description"
disclosure link, by contrast, is a once-per-submission link comparable to stage 1's Record Payment
disclosure fixes, so it got `hitSlop={8}` instead — no visual change.

**Reconnect & Pay (`app/reconnect/[uuid].tsx`) and Disconnect (`app/disconnect/[uuid].tsx`):** Both
screens signaled a successful action with a native `Alert.alert(...)` populated with a "Done" button
that called `router.back()`. Banking-app UX research is consistent that a dedicated in-app
confirmation view — not an OS-native dialog — is the expected pattern for a completed monetary/
status-changing action; a native alert is also the one interaction style this app otherwise never
uses for routine success states (Record Payment, Record Expense, and Log Complaint all show an
in-screen confirmation view instead). Replaced the success `Alert.alert` in both screens with a new
`phase === 'success'` render branch — same visual shape as those three screens' own confirmation
views (large title, body line, hint, a `Done` button), but using the `Badge` `synced` (green) tone
rather than the `offline` (amber) tone those three use: §5 reserves green exclusively for
"confirmed-on-server," and unlike Record Payment/Expense/Complaint (which write to a local queue
first and may sync later), Reconnect and Disconnect are online-only API calls — by the time this
view renders, the result is already confirmed on the server, so amber's "saved, will sync later"
framing would be actively wrong here. The pre-action `Alert.alert` "Confirm reconnection payment" /
"Confirm disconnection" dialogs were deliberately left as native alerts — that's a point-of-no-return
gate for an irreversible action, a different UX role than signaling success, and native confirm
dialogs are the expected pattern for that specific role too.

**Emergency (`app/emergency.tsx`):** Added an "Acknowledge all N" bulk action, shown only when more
than one item is queued (e.g. an agent who hasn't opened the app in a few days while several
complaints separately crossed 48h). Backed directly by incident-tooling precedent — PagerDuty
supports a bulk acknowledge-all alongside individual acknowledges for exactly this reason: forcing N
separate confirm-taps on a screen already gated behind "rare + high-stakes enough to justify an
interrupt" adds friction without adding safety. Bulk action calls the same per-item
`syncManager.acknowledgeEmergency()` in a sequential loop (not `Promise.all`, to keep the SQLite
writes and `notificationStore` republishes ordered) — every item is still individually recorded as
acknowledged, so this doesn't weaken the "every acknowledgment is a real, tracked, per-complaint
action" guarantee `complaint-desk.md` §5 relies on; it only removes the repeated-tapping tax. Left
everything else about this screen unchanged: no back button, no swipe-to-dismiss, no bulk *dismiss*
(only bulk *acknowledge*, preserving the acknowledge-vs-dismiss distinction complaint-desk.md §6/§7
is explicit must never be blurred).

**Sync Status (`app/sync-status.tsx`):** No changes. Already does everything this pass's research
would otherwise recommend — determinate sync progress, plain-language errors via
`humanizeSyncError`, a manual "Sync Now" trigger, and its own live `subscribeSyncState`-triggered
refresh (the pattern this pass ported to History and Notifications came from here). Confirmed
"already good," not an oversight.

**Dead-end/navigation audit:** Checked every in-scope screen for the same class of bug as stage 1's
Record Payment fix. All five modal screens (`log-complaint`, `notifications`, `sync-status`,
`reconnect/[uuid]`, `disconnect/[uuid]`) are registered in `app/_layout.tsx` with
`headerShown: true`, giving each a real native back arrow — confirmed by reading the Stack
registration directly, not assumed. `emergency.tsx`'s lack of a back button/gesture is deliberate and
unchanged on iOS (the one screen where that's correct — see its own doc comment). No dead ends found; none
introduced.

**Android hardware back button on `emergency.tsx` (2026-08-27, stage 3 fix — documented here since it
landed in code but was missed in this section's original writeup):** `gestureEnabled: false` on this
route's `Stack.Screen` only suppresses iOS's edge-swipe-to-dismiss gesture — per
`@react-navigation/native-stack`'s own docs, it has no effect on Android. Without an explicit
`BackHandler` interception, Android's hardware/gesture-nav back button would silently call
`goBack()` and let an agent leave this screen having acknowledged nothing — defeating the "the ONLY
way off this screen is Acknowledge" guarantee this section and `complaint-desk.md` §7 both rely on.
Fixed with a `BackHandler.addEventListener('hardwareBackPress', () => true)` that consumes the event
unconditionally while the screen is focused, matching the no-gesture/no-header/no-back treatment
already applied on iOS. This was a real, previously-unclosed gap on Android specifically — not a
platform where "deliberate and unchanged" was actually true until this fix.

**Shared primitives:** None added or changed this pass. `dangerOutline` and `StatCard`'s `onPress`
(stage 1's additions) were evaluated for reuse here but didn't genuinely fit any in-scope screen —
Reconnect/Disconnect's destructive-vs-primary weighting question doesn't arise the same way Customer
Detail's did (each screen has exactly one primary action, no competing secondary one), and none of
these screens have a tile-grid layout `StatCard` would suit.

**Deliberately NOT changed:** Did not add pull-to-refresh (`RefreshControl`) to History or
Notifications despite both being lists — considered it, but no screen in this app uses that pattern
today, and this app already has an established, working "how does new data arrive" convention (the
sync triggers in §2, surfaced via `subscribeSyncState`/`subscribeNotificationsState`); introducing a
second, parallel refresh mechanism found nowhere else in the app would add an inconsistent
interaction pattern to solve a problem the live-subscription fix above already solves more
consistently. Did not touch `EmergencyBanner.tsx`, `notificationStore.ts`, or `emergencyState.ts` —
read them closely (they're stage 1-adjacent infrastructure, not stage 2 screens) and found them
already correctly implementing complaint-desk.md §7's spec exactly as written.

**Flagged for the product owner, not touched (stage 1's screens):** Reconnect and Disconnect's
pre-action confirm step is a native `Alert.alert`, matching a pattern Customer Detail's Record
Payment/Record Expense flows don't use at all (those go straight to their own form, no interstitial
confirm). If a future pass wants full consistency, that confirm step could become an in-app review
screen instead — deliberately not changed here since it's a working, reasonable pattern in its own
right (see this section's Reconnect/Disconnect writeup) and touching it would be a bigger, riskier
change than this pass's brief called for.

## 10. Visual rebrand pass — 2026-08-27 ("MTN MoMo quality bar," post-stage-2)

After stages 1 and 2 (both §8/§9, both landed earlier the same day), the product owner reviewed the
result and said plainly: "I am not impressed with the UI/UX at all," and asked to "mimic the MTN
MoMo app." That request was deliberately reinterpreted, not followed literally: this pass studied
and applied MTN MoMo's *UX quality and interaction patterns* (bold hero balance cards, confident
high-contrast color, card elevation, big legible numerals, satisfying tap feedback) — it does **not**
reproduce MTN's actual trademarked yellow/black brand identity or logo. SWECOM/CNCMS is not MTN;
copying a real company's specific brand identity onto an unrelated product would be brand
impersonation, not a rebrand. Confirmed via research (see below) that MTN's own 2022 identity
redesign is explicitly "black on white, and black on yellow" — this app's new palette shares no hue
family with that at all, by design.

### Research (web search, not worked from memory)

- **MTN MoMo app itself** — a detailed 2026 user-experience review of the new MTN MoMo app
  (Uganda) ([ssmusoke.com](https://ssmusoke.com/2026/03/19/review-of-new-mtn-momo-app/)) describes
  it as "a clear improvement from a UI/UX perspective, with a modern design," calls out the balance
  card showing account + points balances with **a hide-balance toggle for privacy**, and separately
  notes visual polish arriving ahead of some functional fixes — i.e. the visual layer and the
  functional layer are explicitly reported as improving on different tracks, which matches this
  pass's own scope (visual system only, no behavior/feature changes beyond the primitives listed
  below).
- **Airtel Money** — a first-hand 2026 comparison piece
  ([moseskemibaro.medium.com](https://moseskemibaro.medium.com/i-finally-used-airtel-money-it-completely-changed-how-i-think-about-m-pesa-safaricom-f76e70091612))
  and a UI/UX case study ([think.design](https://think.design/work/airtel-payments-bank-ui-ux-design/))
  both emphasize **restraint** as the strongest UX trait — the home screen leads with balance +
  clear CTAs, success/receipt screens are styled as verifiable records rather than upsell surfaces.
- **M-Pesa** ("My OneApp") — balance-first home screen with smart shortcuts/quick-access tiles
  (Google Play listing, corroborated by a redesign concept piece,
  [medium.com/@allan.kimutai1](https://medium.com/@allan.kimutai1/redesigning-m-pesa-a-simpler-smarter-future-for-mobile-money-86c90c24b66d)) —
  same "the balance is the anchor, everything else is a tile around it" shape this app's Home
  already had structurally (§8's zone-snapshot work); this pass's job was making that anchor
  *look* like one.
- **General fintech/mobile-money UI research, 2026** (multiple sources, e.g.
  [eleken.co](https://www.eleken.co/blog-posts/modern-fintech-design-guide),
  [wandr.studio](https://www.wandr.studio/blog/fintech-mobile-app-design-trends)) — converging
  findings directly applied below: "the important number is unmistakably the largest, secondary
  details are grouped and quieted, and color is used with restraint to mean something specific
  rather than to decorate" (→ bigger `fontSize.display`/`xxl`, one hero card per screen, not a
  wall of colored tiles); "rounded corners and subtle elevation look polished without feeling
  heavy" (→ `radius`/`shadow` token changes below); card-UI is "the most familiar interface format
  for mobile users interacting with many services" (→ kept the existing Card-based layout, no
  structural navigation change).

### Palette — `mobile/src/theme/colors.ts` (verified, not eyeballed)

A Node script implementing the WCAG relative-luminance/contrast formula
(`(L_lighter + 0.05) / (L_darker + 0.05)`) was written and run against every color pair actually
used in this codebase (not just the raw list) before any value was chosen, including several
**pre-existing** colors this pass found already fell short of the app's own stated AAA 7:1 minimum:

| Token | Old | Old ratio | New | New ratio | Context verified |
|---|---|---|---|---|---|
| `accent.home` | `#1D4ED8` | 6.70:1 (AA only) | `#1E40AF` | 8.72:1 | white text on fill |
| `accent.customers` | `#4338CA` | 7.90:1 | `#3730A3` | 9.93:1 | white text on fill (Customers filter chip) |
| `accent.payment` | `#15803D` | 5.02:1 (AA only) | `#065F46` | 7.68:1 | white text on fill (**Button `primary`** — the app's single most-used button was under AAA) |
| `accent.history` | `#B45309` | 5.02:1 (AA only) | `#8A3D0C`* | 7.63:1 | white text on fill (History filter chip) |
| `accent.expense` | `#7E22CE` | 6.98:1 (AA only) | `#6B21A8` | 8.72:1 | white text on fill — promotes stage-1's local `record-expense.tsx` fix to the shared token |
| `accent.complaint` | `#A21CAF` | 6.32:1 (AA only) | `#86198F` | 8.24:1 | white text on fill |
| `status.offlineFg` | `#92400E` | 6.37:1 (AA only) | `#78350F` | 8.15:1 | text on `offlineBg` tint |
| `status.syncedFg` / `verifiedFg` | `#166534` | 6.49:1 (AA only) | `#14532D` | 8.30:1 | text on tint |
| `status.errorFg` / `rejectedFg` | `#991B1B` | 6.80:1 (AA only) | `#7F1D1D` | 8.20:1 | text on tint |
| `danger` | `#B91C1C` | 6.47:1 (AA only) | `#7F1D1D` | 10.02:1 / 8.20:1 | text-on-white **and** white-on-fill **and** text-on-`dangerBg` — one shade now covers all three, previously three near-duplicate reds each independently under AAA |
| `border` | `#CBD5E1` | n/a (not text) | `#E2E8F0` | n/a | lightened — see "elevation over border" note below |

*`#8A3D0C` is a custom shade between Tailwind's amber-800 (`#92400E`, 7.09:1 — passes but by a
razor-thin margin for a stated *minimum*) and amber-900 (`#78350F`, 9.07:1 but visibly brown, not
amber) — tuned for real headroom without losing the hue's identity.

`status.syncingFg`/`syncingDot`, `accent.*Dot` colors, `pendingFg`, `whatsapp`, and every background
tint were left unchanged — either already AAA (`syncingFg` was already blue-800 at 7.15:1) or not
text (dots, tints have no contrast requirement of their own). Every hue's *family* (blue=home,
indigo=customers, green/emerald=payment, amber=history, purple=expense, fuchsia=complaint) is
unchanged — only the exact shade, one Tailwind step deeper each, consistent with the "color with
restraint, meaning something specific" research finding above (nav-accent-per-feature-area is
exactly that pattern; this pass makes each already-established hue actually clear AAA rather than
mostly clearing AA).

`accent.payment` (now a richer emerald-800, cooler/more premium-reading than the old flat green-700)
doubles as this app's de facto brand-primary — it's `Button`'s `primary` fill, `TextInput`'s focus
ring, and the new Home hero card's fill. Deliberately not a separate `brand.primary` token: "Record
a payment" already is this app's single signature action, so one color serving both roles avoids a
second near-identical green.

### Sacred constraints — verified preserved, not just assumed

- **AAA 7:1 minimum** — every color this pass touched now has a verified ratio ≥7:1 in every
  context it's actually painted in (table above). Nothing new was introduced below AAA.
- **Offline-status semantic rule** — amber/calm = offline-and-queuing, never red. Completely
  untouched: `offlineBg`/`offlineDot`/`syncingBg`/`syncingDot`/`syncedBg`/`syncedDot`/`errorBg`/
  `errorDot` are all byte-for-byte identical to before this pass. Only `offlineFg`'s exact amber
  *shade* was darkened for contrast (still unambiguously amber, not red — see table). Which state
  gets which hue family was never in question.
- **Dark mode** — still not introduced. No new dark-mode tokens, no `useColorScheme` reads added
  anywhere in this pass.
- **No gradients, no backdrop-blur/glassmorphism** — no gradient was added anywhere, despite the
  task brief explicitly permitting a reasoned, subtle exception. Solid, deeply-saturated fills plus
  the new `shadow.hero` elevation (a drop shadow — a depth cue, not a translucency effect, and
  costs nothing like a live blur does) already deliver the "bold hero card" quality bar without a
  gradient's added render cost or its risk of reading as decorative rather than functional in
  direct sunlight. The Home hero card's secondary text (`#ECFDF5`, "emerald-50") is a **solid,
  fully-opaque** near-white, not an `rgba()` translucent white — chosen specifically so its contrast
  ratio is a real, directly-measurable number (7.29:1) rather than something that only reads
  correctly once composited over one specific fill color, which edges toward the glassmorphism this
  app rules out.
- **Touch-target floor** — `touchTarget.primary`/`touchTarget.floor` (56dp/48dp) in `tokens.ts` are
  completely unchanged. No component's tappable height was reduced by this pass.

### `mobile/src/theme/tokens.ts`

- `radius`: `sm` 6→8, `md` 10→14, `lg` 14→20, new `xl` 28 (reserved for the hero card), `pill`
  unchanged. Pure value bump — cascades rounder corners to every Button/Card/TextInput/chip
  automatically, the cheapest, lowest-risk "modern fintech" signal available (no layout risk,
  unlike gradients/blur).
- `fontSize`: `xxl` 28→32 (used only for "the one big number on this screen" pattern — StatCard
  value, sync-status last-sync value, login title, emergency header), `display` 36→40 (used only by
  Home's hero total and Record Payment's amount display/confirm amount). Both bumps are pure
  token-value changes that cascade into `record-payment/index.tsx` and other untouched screens
  automatically — intended, not a scope violation, per the brief's "changing the VALUES... cascades
  the new palette/scale... in one pass" framing.
- New `shadow` export: `shadow.card` (subtle default lift, every `Card`) and `shadow.hero` (stronger
  lift, filled/hero cards only) — RN `shadowColor`/`shadowOffset`/`shadowOpacity`/`shadowRadius` +
  Android `elevation`, not CSS blur/backdrop-filter.
- `spacing` and `touchTarget` — unchanged.

### Primitives (`mobile/src/components/ui/`) — every existing prop kept working

- **`Card.tsx`** — new `variant?: 'outlined' | 'filled'` (default `'outlined'`, so all 15+ existing
  call sites render unchanged except for the free `radius`/`shadow.card` token cascade) and
  `fillColor?: string`. `'filled'` renders a solid-color, no-border, `radius.xl`, `shadow.hero`
  "hero" card — built for Home's collection total but reusable by any future screen. Default
  (outlined) cards now also carry `shadow.card` for the first time (previously flat/no shadow at
  all).
- **`Button.tsx`** — label `fontWeight` 600→700. Solid-fill variants (`primary`, `danger`) gain
  `shadow.card`; outline/ghost variants stay flat (a shadow under a transparent background looks
  like a smudge, not a lift). New press feedback: `transform: scale(0.97)` on press, layered on top
  of the existing opacity dip (0.85→0.9, slightly less aggressive since scale now also signals the
  tap) — the "satisfying tap feedback" research consistently attributes to polished mobile-money
  apps. `dangerOutline`/`danger` variant behavior otherwise unchanged.
- **`StatCard.tsx`** — value `fontWeight` 700→800 (size already cascades via `fontSize.xxl`). No
  prop changes.
- **`Badge.tsx`** — label `fontWeight` 700→800, pill `paddingVertical` 4→6 (a deliberate scoped
  value, not a `spacing` token bump, since `spacing.xs` is reused elsewhere for tight gaps this
  shouldn't affect). No prop changes.
- **`TextInput.tsx`** — new focus-ring behavior: 2px `colors.accent.payment` border while focused
  (was always 1px `colors.border`). Implemented with local `useState`, and any caller-supplied
  `onFocus`/`onBlur` is still called through — fully backward-compatible, verified no existing call
  site currently passes either prop (grepped first).
- **`EmptyState.tsx`** — title `fontSize` `lg`(18)→`xl`(22). No prop changes.
- **`SyncStatusStrip.tsx`** — label `fontWeight` 600→700 only. Deliberately the smallest touch of
  any primitive in this pass — see "deliberately not changed" below.

### Home screen (`app/(tabs)/index.tsx`) — the concrete demonstration

"Today's collection" is now a `Card variant="filled"` hero card: solid `accent.payment` (emerald-800)
fill, an uppercase eyebrow label (`"TODAY'S COLLECTION"`, solid `#ECFDF5`, letter-spaced), a small
white circular badge with a "₣" glyph (a lightweight text-glyph icon — no icon library added, matches
this codebase's existing precedent of unicode glyphs in `SyncStatusStrip`/`record-expense.tsx`'s
category picker), the total itself in white at `fontSize.display` (now 40, `fontWeight: '800'`), and
a hint line in the same solid off-white. This is the single concrete instance of every token/primitive
change above acting together: the new deeper `accent.payment`, the new `radius.xl`/`shadow.hero`,
the new `fontSize.display`, all composed through `Card`'s new `variant="filled"` rather than
one-off styling.

The "Zone arrears outstanding" card directly below it deliberately stays the outlined style (not a
second hero card) — documented inline in the screen's own comment: a zone carrying some arrears is
the normal, expected day-to-day state, not a problem, so a full-bleed red hero block every time the
app opens would read as a standing alarm — the exact same "calm until actually urgent" principle §5
already applies to the sync strip (amber, not red, for normal offline operation), applied here to a
second "is this actually bad?" judgment call. The two StatCard tiles and everything below (Quick
actions, Notifications, Get around) are structurally untouched — they inherit the token cascade
(rounder corners, card shadow, bolder StatCard values) automatically, no direct edits needed.

**Considered, deliberately not built:** MTN MoMo's own hide-balance-for-privacy toggle (found in the
research above) would be a natural fit for a cash-handling field agent, but it's a new interactive
feature, not a visual-system change — out of scope for a rebrand pass and flagged here for whichever
future pass covers Home's actual feature set, not silently dropped.

### Verification

- `cd mobile && npx tsc --noEmit` — clean except the two pre-existing `src/api/devices.ts` errors
  (`RegisterPushTokenRequestBody`/`RegisterPushTokenResponse` not exported from `types/api`),
  unrelated to this pass and out of scope.
- `npm test` — 74/74 passing, unchanged from before this pass. This pass touched zero
  `src/utils/*` pure-function logic — every change was colors/tokens/component styling/one screen's
  JSX structure.

### Deliberately NOT changed, and why

- **`mobile/app/*`** — nothing under `app/` was touched except `(tabs)/index.tsx` as scoped. Every
  other screen gets the palette/token cascade automatically and will read the new primitives
  correctly the moment a future pass touches them; none were restructured here, per the explicit
  "7 more agents about to build new screens" constraint.
- **`record-expense.tsx`'s local `#6B21A8` override** — now redundant (the shared `accent.expense`
  token is that exact value), but left in place rather than removed, since removing it means editing
  a file under `app/` this pass was told not to touch. Harmless: still correct, just a little
  duplicated safety margin.
- **Dark mode** — not introduced (see sacred-constraints list above).
- **A new icon library** — the hero card's "₣" glyph and every other glyph in this app remain plain
  Unicode text, matching existing precedent, not a new dependency.
- **Any component-rendering test framework** — this app's tested-surface convention (`src/utils/*`
  only, via `node --test`) is unchanged; nothing in this pass needed or added test coverage beyond
  what already existed.

### Brief for the next wave of agents (new-screen builders)

1. **Don't hardcode colors, radii, font sizes, or shadows.** Read them from `colors.ts`/`tokens.ts`.
   If a new screen needs "the app's brand color," that's `colors.accent.payment` — don't introduce a
   second green.
2. **Default to `Card` (`variant="outlined"`, i.e. just `<Card>`)** for ordinary content. Reach for
   `<Card variant="filled">` only for a screen's single most important figure — at most one hero
   card per screen. Two hero cards on one screen defeats the "the eye goes here first" purpose.
3. **If you introduce a new fill color for a hero card**, re-verify contrast for every text color
   painted on it — do not copy `#ECFDF5`/`#FFFFFF` from Home's hero card assuming it transfers; it
   was verified specifically against `accent.payment`'s current hex.
4. **`Button`, `Badge`, `StatCard`, `TextInput`, `EmptyState` all already look and behave correctly**
   with zero extra work — just use them with their existing prop APIs (all backward-compatible;
   nothing in this pass requires updating existing call sites).
5. **Still no gradients, no blur/glassmorphism, no charts.** Shadows (`shadow.card`/`shadow.hero`)
   are fine and encouraged for card-like elements; they are not the same thing §6 rules out.
6. **Still AAA (7:1), still amber-never-red-for-offline, still 56dp/48dp touch targets, still no dark
   mode.** None of this pass's changes loosened any of those — verify new colors the same way this
   pass did (a real contrast calculation, not eyeballing) before introducing any new token value.

## 11. Seven new screens + a 5th tab — 2026-08-27 (post-rebrand)

Seven agents, each scoped to exactly one new top-level screen file (plus, where genuinely needed, a
small owned backend endpoint), built in parallel immediately after §10's rebrand landed — using the
new design system from the start rather than needing a second retrofit pass. A dedicated orchestration
step (this section) wired navigation afterward, deliberately kept out of the parallel agents' hands
since a shared nav file is exactly the kind of file two agents editing at once would conflict on.

**Screens added** (each is a standalone `Stack.Screen`, `presentation: 'modal'`, registered in
`app/_layout.tsx` alongside the existing `notifications`/`sync-status`/`log-complaint` entries):

- **`settings.tsx`** — profile display (from the already-cached `/auth/me`), app version, sync-aware
  logout. Notification preferences and a language picker were deliberately left out: `NotificationSetting`
  is a single per-tenant row (admin-only, not per-user), and `language-support.md` has no real
  endpoint yet — building either would mean a form that silently does nothing.
- **`reports.tsx`** — plain-numeral (no charts, per §6) collection totals and zone snapshot, backed by
  local SQLite only. `ReportController` has no REST route reachable from mobile's Sanctum client at
  all today (web/Inertia only) — this doesn't fake one; a real API endpoint is a real future gap, not
  solved here.
- **`resources.tsx`** — the agent's own recorded-expenditure history (period total, category filters,
  sync-status badges). Deliberately excludes the office-only P&L/budget-vs-actual dashboard —
  `ExpenditurePolicy::viewDashboard()` explicitly admits `super/admin/manager` only, never `agent`.
- **`zones.tsx`** — read-only: the agent's own zone (derived from the already-synced local customers
  cache, since every cached row shares one `zone_uuid`) plus a live `GET /api/v1/zones` list for
  looking up any other zone by name. No management UI — `ZonePolicy` keeps create/update/delete
  office-only.
- **`agent-profile.tsx`** ("My Profile") — the logged-in agent's own record only, *never* a roster,
  even though `AgentPolicy::view()`/`viewAny()` technically permit any authenticated role to view any
  agent. A lost/borrowed field phone showing every other agent's salary and marital status has no
  product justification, so this is scoped narrower than the raw policy allows on purpose. Backed by
  a new `GET /api/v1/agents/me` (registered ahead of the `apiResource`'s `{agent}` route so it isn't
  swallowed), which resolves strictly from `$request->user()->id` — no uuid parameter exists on this
  endpoint at all, so there is no way to redirect it at another agent's data regardless of role. A new
  `AgentMeResource` is kept separate from the existing roster `AgentResource` so `index()`/`show()`'s
  shape isn't widened.
- **`disconnections.tsx`** — the zone-scoped "flagged for non-payment" eligibility list (not the
  office bulk workboard), tapping through to the existing `disconnect/[uuid].tsx` flow. Backed by a
  new `GET /api/v1/customers/eligible-for-disconnection`, reusing `CustomerEligibilityService`
  directly (no duplicated query logic), gated by `CustomerPolicy::viewEligibilityBoard()` (already
  admitted `agent`, scoped to their own zone). Zone-scoping is server-enforced exactly like the web
  `DisconnectionsController`: for an `agent` caller the zone id comes from `TenantContext::zoneId`
  regardless of any `zone_uuid` query param sent — covered by a dedicated regression test
  (`test_agent_cannot_view_another_zone_via_query_param`).
- **`complaints.tsx`** — this device's own submitted complaints, rendering instantly from local
  SQLite (offline-first, never blocks). A real data gap surfaced here: complaint sync is genuinely
  push/create-only (`SyncService::pull()` never returns complaints), so the local cache alone can
  never carry the office's real open/in_progress/resolved status. Closed with a best-effort,
  online-only enrichment call to the already-existing `GET /api/v1/complaints`, matched back to local
  rows by `server_uuid` — not-yet-synced rows show the existing "Saved · will sync" amber badge,
  synced-but-unconfirmed rows show a distinct neutral "Submitted," and a successful live fetch shows
  the real status plus resolution note. No resolve/reopen/assign UI — stays office-only.

**Common thread across all seven:** every screen mirrors the *exact* role/ability gate its
corresponding web `Policy` class already enforces — nothing here invents a new permission or widens
what an `agent` could already do server-side. Two of the seven (Agent Profile, Disconnections) needed
a small new backend endpoint since none existed for mobile to call; both were kept minimal (one method
each, reusing existing services, no new service classes) and covered by dedicated new feature tests,
independently re-run and confirmed passing before landing (not just trusted from each agent's own report).

## 12. Shared `DetailRow` primitive + self-service profile/password — 2026-08-27 addendum

Two follow-ups landed after §11's seven-screen parallel build, both scoped narrowly.

**`DetailRow` extraction.** `settings.tsx` and `agent-profile.tsx` (two of the seven §11 screens,
built by different agents in the same parallel wave without seeing each other's work) each
independently built a pixel-for-pixel identical local `Field({label, value, last})` helper plus
matching `fieldRow`/`fieldRowDivider`/`fieldLabel`/`fieldValue` styles — the classic parallel-build
duplication. Extracted to `src/components/ui/DetailRow.tsx` (same doc-comment/prop-typing
convention as `Badge.tsx`/`StatCard.tsx`), and both screens now import it instead of defining their
own copy. `app/(tabs)/record-payment/index.tsx` was checked too (a stray note from the §11 testing
pass claimed it had "the same inline pattern") — it doesn't: its `fieldLabel` style is a standalone
section caption above a segmented control/input, not a label-left/value-right divider row, and it
has no `fieldRow`/`fieldValue` pair at all. Left untouched rather than forced into `DetailRow`.

**Self-service profile & password update — a real, previously-missing feature.** Confirmed by grep
before starting: no profile-edit or password-change endpoint existed anywhere in this codebase, web
or mobile — only `SettingsUserController` (admin editing OTHER users). `settings.tsx`'s Profile
section had been read-only for exactly this reason (see that screen's own now-updated doc comment).
Built the real thing:

- **`PATCH /api/v1/auth/profile`** (`AuthController::updateProfile()`, `UpdateProfileRequest`) —
  updates the authenticated user's own `name`/`username`/`email` only (all `'sometimes'`).
  `username`/`email` uniqueness validated via `Rule::unique('pgsql.users', ...)->ignore($userId)`.
  No route parameter exists on this endpoint at all — it resolves strictly from `$request->user()`,
  the same "can't be pointed at another user's row" shape as `AgentController::me()`.
  `status`/`password`/anything else can never surface through it (not defined in the Request's
  `rules()`, so absent from `validated()` regardless of what a caller sends).
- **`PATCH /api/v1/auth/password`** (`AuthController::updatePassword()`, `UpdatePasswordRequest`) —
  requires `current_password` (verified via `Hash::check()`, rejected with a `current_password`
  validation error if wrong — never a bare 403/500), `new_password` (`confirmed`,
  `Password::min(8)->letters()->numbers()` — stricter than this codebase's existing plain `min:8`
  floor for admin-created accounts, since a self-service change is the one path where the person
  choosing the password is the one who has to trust it later), `new_password_confirmation`. **On
  success, every OTHER active Sanctum token for this user is revoked** (current token explicitly
  excluded via `currentAccessToken()->id`) — a deliberate security default, not an oversight. Real
  UX consequence: a second device/session logged in under the same account gets logged out
  immediately and must re-authenticate with the new password. Accepted at this app's current scale
  (~6 users, one tenant) since a password change is rare and is exactly the moment an
  old/unexpected session should stop working.
- Both endpoints gated by `auth:sanctum` only — placed alongside `logout()` in `routes/api.php`,
  deliberately OUTSIDE the `ResolveTenant`/`throttle:api` group `auth/me` and every tenant-scoped
  resource route sit inside, since both only ever touch the central `users` table, never
  tenant-scoped data. No Policy check (`authorize()` returns `true` in both new Form Requests) —
  mirrors `AuthController::me()`/`logout()`'s existing no-separate-authorization shape, since there
  is no "other user's data" to protect against on an endpoint with no route parameter.
- Feature tests added to `tests/Feature/Api/AuthTest.php` (kept in the existing file rather than a
  new `ProfileTest.php` — same controller, same auth-endpoint scope the file already covers):
  successful profile update, username/email uniqueness rejection (including "keep your own current
  value" not tripping the uniqueness check), successful password change, wrong-current-password
  rejection, weak-new-password rejection, and a token-revocation test confirming the current token
  survives while a second token is revoked. All passing — run via
  `php artisan test --filter=AuthTest`, not the full suite (per this repo's own convention).

**Mobile:** two new standalone modal routes, `app/edit-profile.tsx` and `app/change-password.tsx`
(registered in `app/_layout.tsx` exactly like every other §11 screen — `presentation: 'modal'`,
`headerShown: true`), reached from two new "Edit profile"/"Change password" buttons on
`settings.tsx`'s Profile card. Chose the route-per-form shape over React Native's `Modal` component
— this app has zero existing use of RN's `Modal` anywhere; every other reached-one-tap-deeper form
(`record-expense.tsx`, `log-complaint.tsx`, `reconnect/[uuid].tsx`, `disconnect/[uuid].tsx`) is
already its own Stack route with `presentation: 'modal'`, so this follows that established
convention rather than introducing a second, inconsistent one. Client-side validation added to
`src/utils/validation.ts` as `validateProfileForm`/`validatePasswordForm` (same pure,
screen-free, `node --test`-covered shape as `validateComplaintForm`/`validateExpenditureForm`),
mirroring the server's password rules so a weak password is caught before the round-trip. Server
422 field errors (uniqueness, wrong current password) are extracted locally in each screen (a small
inline `error.response.data.errors[field]` read, matching `agent-profile.tsx`'s existing precedent
of a local, non-shared error-shape check) rather than widening `src/api/client.ts`'s
`extractErrorMessage` — that helper only ever surfaces the generic top-level `message`, which is
fine for every existing caller but not specific enough for "this username is already taken."

On a successful profile save, `edit-profile.tsx` calls a new `AuthContext.updateCachedUser(patch)`,
which merges the patch into both in-memory `user` state and the SecureStore-cached profile
(`writeCachedProfile`) — the display refreshes immediately with no re-login and no extra `/auth/me`
round-trip, reusing the exact cache-write helper `login()` already uses internally. On a successful
password change, `change-password.tsx` deliberately does nothing to local auth/token state beyond
showing a confirmation — the server revokes *other* tokens, and this device's own token (the one
that made the request) was never touched, so the agent stays signed in exactly as before; verified
this assumption against the controller's actual implementation (see above) rather than assuming it.

**Verification:** `cd mobile && npx tsc --noEmit` clean except the two pre-existing
`src/api/devices.ts` errors (unrelated, unchanged, called out as expected in every prior pass's own
verification section too). `npm test` passing, including the new `validateProfileForm`/
`validatePasswordForm` cases added to `src/utils/__tests__/validation.test.ts`.

**Deliberately NOT built:** a matching web-admin self-service profile/password UI. This work was
scoped to the mobile app specifically (the task that produced this addendum was mobile-focused);
the two new endpoints are plain `auth:sanctum` API routes with no Inertia/web page behind them, so a
web self-service page is a real, separate, currently-open gap for whoever picks up web-admin
self-service next — not silently solved here and not pretended to be out of scope for lack of need.

## 13. Manuscript screen + a real zone-scoping gap closed in the API — 2026-08-27 addendum

Built `app/manuscript.tsx` (route `/manuscript`), the eighth new-screen addition after §11's
seven. Product owner's framing: "though I don't think is that necessary, but I think is good to
have the feature" — scoped as a modest read-only view, not a mobile port of the web Manuscripts
register (`resources/tsx/pages/Manuscripts/Index.tsx`, a full paginated/filterable/exportable
office tool).

**Why this task started with a real incident, not a blank slate.** Immediately before this build,
1,509 bogus manuscript rows for nonsense future periods (`2031-01`/`2031-02`) were found and
deleted from the real dev database — generated for all 446 customers, and picked up as "current"
everywhere by `Customer::latestManuscript()` (a `hasOne(...)->latestOfMany('period')` relationship
that trusts whatever period sorts highest as a *string*, with no sanity bound against the real
current calendar period). `latestManuscript()`'s own doc comment now documents this exact failure
mode. This screen was built under an explicit mandate not to repeat it.

**Scope decision — summary AND per-customer list, not one or the other.** A zone is only "low
hundreds" of customers (§2's stated data scale), small enough that a full per-customer list is
still a quick scroll, not the kind of thing that needs office-grade pagination. The screen shows
one hero card (period total billed), two `StatCard` tiles (arrears outstanding, collected so far
with a collection-rate hint), a conditional credit-balance card, then every customer's bill/
arrears/credit/total_bill for the period, sorted client-side by arrears descending (highest-owing
first — matches the Customers list's existing "arrears is what the agent is walking the zone to
collect" convention). Rows are deliberately non-interactive plain `Card`s — no drill-down into
Customer Detail, no bill-send action; this is a glance, not a workflow. A footnote discloses that
the money figures (bill/arrears/credit/collected) are active-customers-only per
`ManuscriptRepository::aggregates()`'s own scoping, while the customer count and per-customer list
below it include every status — stated explicitly rather than left to look like the two always
agree.

**Period safety — confirmed by reading the real code, not assumed.** `Api\ManuscriptController::
index()` (already existed, reachable from mobile's Sanctum client at `GET /api/v1/manuscripts` —
this was NOT a Reports-style "no endpoint exists" gap) takes an explicit `period` query param,
and `App\Services\ManuscriptService::scopedFilters()` defaults it to `Carbon::now()->format('Y-m')`
and validates the format (`/^\d{4}-(0[1-9]|1[0-2])$/`) when omitted — genuinely period-bounded, the
opposite of `latestManuscript()`'s trap. `src/api/manuscripts.ts`'s `fetchManuscripts()` still
never omits `period` — it always computes and sends today's real calendar period client-side
(`currentPeriod()`, a plain local-date computation, never derived from any cached/server value) as
a deliberate belt-and-braces redundancy on top of the server's own independent validation, and
documents the incident inline so a future edit to this call site can't accidentally drop it back to
"trust the server's default silently" without at least reading why it's there.

**A real gap found and closed in the API itself — zone scoping.** `Api\ManuscriptController::
index()` passed an agent's own `zone_uuid` query value straight through to the query unchecked —
unlike its sibling `CustomerController::eligibleForDisconnection()`, which force-applies
`TenantContext::zoneId` for the `agent` role and ignores any `zone_uuid` the caller sends. This
meant an agent could see another zone's (or, by omitting `zone_uuid`, the whole tenant's)
manuscript figures by tampering with the query string — not the period trap this task was
explicitly warned about, but a real, adjacent authorization gap in the same endpoint this screen
needed to call, found by reading the controller against its own established sibling pattern.
Fixed by mirroring `eligibleForDisconnection()` exactly: `Api\ManuscriptController` now takes a
`TenantContext` dependency and, for the `agent` role, discards any `zone_uuid` sent and forces
`zone_id` from `TenantContext::zoneId` before calling `ManuscriptService::list()`/`summary()`.
Office roles (manager/admin/super) are unaffected — unscoped by default, may still pass
`zone_uuid` to filter, matching the web register's existing behavior. Two new regression tests
added to `tests/Feature/Api/ManuscriptTest.php`
(`test_agent_cannot_view_another_zones_manuscripts_via_query_param`,
`test_agent_sees_only_their_own_zones_manuscripts_by_default`), mirroring
`CustomerEligibilityTest::test_agent_cannot_view_another_zone_via_query_param`'s exact pattern. All
8 tests in that file pass: `php artisan test --filter="Tests\Feature\Api\ManuscriptTest"` (run
filtered per this repo's own convention, after confirming no other test process was already
running against the shared test DB — `Get-CimInstance Win32_Process -Filter "Name='php.exe'"`
showed only `artisan serve`, nothing test-related). The full unfiltered suite was deliberately not
run. Note: `php artisan test --filter=ManuscriptTest` (unqualified) also matches the unrelated
`Tests\Feature\Web\ManuscriptTest`, which crashed independently on its PDF-export test with a
pre-existing dompdf memory issue in this environment — unrelated to this change (that controller
and test were not touched) and not chased further; the fully-qualified filter above avoids pulling
it in.

**Backend files touched:** `app/Http/Controllers/Api/ManuscriptController.php` (the zone-scoping
fix, `App\Services\ManuscriptService`/`ManuscriptRepository` reused unchanged — no duplicated query
logic), `tests/Feature/Api/ManuscriptTest.php` (two new tests). No new service, repository, or DTO
class — this was a small, surgical fix to an existing endpoint, not new backend surface.

**Mobile files added:** `app/manuscript.tsx` (the screen), `src/api/manuscripts.ts`
(`fetchManuscripts()`/`currentPeriod()`), and a new Manuscript section in `src/types/api.ts`
(`ManuscriptListItemApi`/`ManuscriptSummaryApi`/`ManuscriptIndexResponse`, hand-copied from
`ManuscriptResource`/`ManuscriptService::summaryFor()`, per §1's "~15 interfaces by hand" convention).

**Accent color:** `colors.accent.history` — reused, not new. The web sidebar's actual Manuscripts
nav accent is literally `'amber'` (`resources/tsx/layouts/AppLayout.tsx`), and `accent.history` is
already exactly that hue family in this app's palette, with its white-on-fill pairing already
independently verified AAA (~7.63:1) — no new contrast math needed, per §10's brief for new-screen
builders.

**Verification:** `cd mobile && npx tsc --noEmit` — clean except the two pre-existing
`src/api/devices.ts` errors (unrelated, unchanged, called out as expected in every prior pass's
own verification section too). `npm test` — 95/95 passing, unchanged from before this screen (no
new pure-function logic was added under `src/utils/*` — this screen is UI + API calls only, same
shape as `disconnections.tsx`/`reports.tsx`, neither of which added test coverage either).

**Deliberately NOT built:** bill-print, PDF export, or a "run manuscript calculation" trigger on
mobile — `ManuscriptPolicy::export()` is super/admin/manager only and `calculate()` is super/admin
only; neither ability is replicated here regardless of the viewing role, matching the web's
`EXPORT_ROLES`/`CALCULATE_ROLES` gates exactly. No drill-down navigation from a customer row into
Customer Detail — considered, deliberately left out to keep this screen a glance rather than a
workflow entry point (Customer Detail is already reachable from the Customers tab). No local
SQLite cache of manuscript rows — this is live, server-computed billing data, not persisted
offline, same reasoning as `disconnections.tsx`'s eligibility board.

**Navigation:** not registered here, per this build's established cross-agent convention (§11) —
route is simply `/manuscript`, left for a separate wiring step.

**Navigation — the 5th tab.** §4 originally settled on 4 tabs specifically so a single feature
(Log a Complaint) wouldn't get its own dedicated tab. That reasoning doesn't extend to a genuine
grab-bag of 7+ secondary destinations landing in one session — a single **More** tab
(`(tabs)/more.tsx`) is the standard resolution once an app outgrows what 4 top-level destinations can
hold (the same shape most mobile-money apps use once they mature past a single core loop). It's a
plain two-section list (Field tools: Disconnections/Zones/Complaints/Resources/Reports; Account: My
Profile/Settings/Notifications/Sync Status) — a real UX improvement on its own, since Notifications and
Sync Status previously had no consistent home beyond ad-hoc `Card` links scattered on Home. Home's
existing "Quick actions"/"Get around" sections and its own inline "Sign out" button were left
untouched — More is additive, not a replacement for any existing entry point.

## 14. Arrears Adjustment REQUEST screen — 2026-08-28 (mobile counterpart to a web-only feature)

Closed a real gap confirmed by the product owner: the web app has a complete "Adjust Arrears"
maker-checker write-off request+review flow (`references/arrears-adjustment.md`), but mobile had no
way to request one at all — that doc's §7 previously listed "mobile" outright as deliberately out of
scope. This build narrowed that to just the *review* half staying out of scope; the *request* half now
exists on mobile, matching the "mobile creates, web reviews" split already established here for
payments/expenditures/complaints/disconnections (§4/§11's own "no dedicated verify/reject approval UI
on mobile" pattern, reused rather than reinvented).

**Backend gap confirmed before building anything**: `ArrearsAdjustmentController::store()` returns an
Inertia `RedirectResponse`, not JSON — purely web-session-only, unreachable from mobile's Sanctum
client. Added `POST /api/v1/arrears-adjustments` (`Api\ArrearsAdjustmentController::store()`), reusing
the exact same `StoreArrearsAdjustmentRequest`/`ArrearsAdjustmentPolicy`/`ArrearsAdjustmentService::create()`
the web path already used — no validation or business logic duplicated. Deliberately `store()` only;
no `approve()`/`reject()` JSON routes exist. Full detail, including the new
`App\Http\Resources\ArrearsAdjustmentResource` shape and the new
`tests/Feature/Api/ArrearsAdjustmentTest.php` (20/20 passing across both the web and API test classes),
is in `arrears-adjustment.md` §10 — not duplicated here.

**New screen**: `app/adjust-arrears/[uuid].tsx` — a per-customer, online-only modal route (the same
family as `reconnect/[uuid].tsx`/`disconnect/[uuid].tsx`: `Stack.Screen` with `presentation: 'modal'`,
registered in `app/_layout.tsx` alongside those two). `customerUuid` travels as the route's dynamic
`[uuid]` segment, matching those two existing screens' own param shape rather than a query string.

- **Entry point**: Customer Detail's (`app/(tabs)/customers/[uuid].tsx`) "Other actions" cluster gained
  a new, always-visible "Adjust Arrears" button (secondary variant, violet-bordered) — unlike
  Disconnect (status-gated) or Send Bill (phone-on-file-gated), this one has no visibility condition at
  all, since `ArrearsAdjustmentPolicy::create()` is ungated for every role and every customer status.
- **Fields mirror the web modal** (`ArrearsAdjustmentModal.tsx`) closely: reason-category chips (6
  options, chip-row layout ported from `log-complaint.tsx`'s category-chip pattern rather than
  introducing a new picker primitive — this app has no `SelectInput`/dropdown component, so the
  existing "labeled chip row" convention was the natural fit), direction chips (decrease/increase), a
  `YYYY-MM` target-period text field, an amount field, a required notes field, the identical "This does
  not record a payment…" explanatory note (rendered as an accent-bordered `Card`, mirroring how
  `log-complaint.tsx`'s urgent-toggle card and `reconnect/[uuid].tsx`'s fine-toggle card already use
  `accentColor` for a callout), and the same current-balance/balance-after guidance block, computed
  client-side and explicitly labeled "guidance only" exactly like the web version's identical caveat.
- **Fetches the customer's current arrears fresh, online-only** — same `fetchCustomerDetail()` call
  `reconnect`/`disconnect` already use, same reasoning (§4/§7: this needs the real server-side figure,
  not a stale local cache value), same offline/retry-on-reconnect phase machine as those two screens.
- **New pure validator**: `validateArrearsAdjustmentForm` in `src/utils/validation.ts` (target-period
  format + not-future, positive amount, required note — mirrors
  `StoreArrearsAdjustmentRequest::rules()`), covered by 5 new `node --test` cases in
  `src/utils/__tests__/validation.test.ts`, same "pure, screen-free, unit-tested" convention as every
  other form validator in this file.
- **Success state is deliberately NOT the green "confirmed" treatment** Reconnect/Disconnect use.
  Those two are immediate, already-applied server changes; submitting an arrears adjustment request is
  not — it only starts the maker-checker review. The success view uses `Badge tone="pending"` ("Submitted
  — pending approval") with body copy stating plainly that the customer's balance hasn't changed yet and
  still needs office approval. This is the one place this build was most deliberate about not
  overstating what just happened — see arrears-adjustment.md §10 for the full reasoning.

**New accent color, added after checking existing tokens first (this file's own §6/§10 convention)**:
`colors.accent.arrears` (`#5B21B6`, violet-800, ~8.98:1 white-on-fill, verified with the same
relative-luminance script §10's rebrand pass used — not eyeballed). The web modal's purple-600 is a
page-local choice ("the one genuinely unclaimed color" on `Customers/Show.tsx` specifically, per that
component's own doc comment), not the web nav's `NAV_ACCENTS.purple` (which means `Agents`). On mobile,
plain purple is already claimed by `accent.expense` (Record Expense/Resources) — reusing it here would
blur two unrelated feature areas under one hue, against §10's "color with restraint, meaning something
specific" principle, so a new, genuinely-distinct hue was the correct call rather than a reuse.
`StatCard.tsx`'s `toneColors` map required one mechanical follow-up entry (`arrears: colors.accent.arrears`)
purely to keep `npx tsc --noEmit` clean, since `StatCardTone` derives from `AccentKey` — no `StatCard`
on the new screen actually uses that tone.

**Deliberately NOT wired into `app/manuscript.tsx`.** The task brief explicitly invited this if it fit
cleanly; it doesn't. That screen's own class doc states outright: "Rows are deliberately non-interactive
plain Cards — no drill-down into Customer Detail, no bill-send action; this is a glance, not a
workflow." Adding a third per-row action there would reverse a design decision that screen already made
for itself, not merely find room for one. Customer Detail is the complete, single v1 entry point — a
fine, deliberate scope boundary, not a gap.

**Verification**: `cd mobile && npx tsc --noEmit` — clean except the two pre-existing
`src/api/devices.ts` errors (unrelated, unchanged, called out as expected in every prior pass's own
verification section too). `npm test` — 100/100 passing (95 pre-existing + 5 new).

## 15. Stale-arrears bug report — 2026-08-28 (a real, generalizable delta-sync gap, force-full-refresh added)

Product owner report, verbatim: "The mobile app still has stale data, I thought it was supposed to
sync with the actual data now from the backend, same like the web." Filed within an hour of raw SQL
run directly against Postgres deleting the entire August 2026 manuscript period (446 rows) plus 35
stale `command_runs` rows, and applying a missing `manuscripts.command_run_id` migration — none of
it through any app write path (no artisan command, no HTTP request, no queued job).

**Root cause, confirmed by reading the real code, not assumed.** Customer Detail
(`app/(tabs)/customers/[uuid].tsx`) reads its headline arrears figure entirely from the local SQLite
`customers` cache (`getCustomerByUuid()`, no live API call — deliberate offline-first design, see
that screen's own doc comment), and that cache's `total_arrears`/`credit` columns are populated
solely by `SyncManager.pull()` → `upsertCustomers()` from the server's `SyncService::
upsertedCustomers()`. That query is a **delta** pull filtered by
`Customer::query()->where('updated_at', '>=', $sinceAt)` (`app/Services/SyncService.php`) — it
returns `total_arrears`/`credit` sourced from `$customer->latestManuscript`, but the WHERE clause
gates on the `customers` row's own `updated_at`, not the manuscript's. **No `$touches` relationship
exists from `Manuscript` back to `Customer`** (confirmed: grepped every model), and neither
`ManuscriptCalculate`/`CustomerManuscriptRecalculationService` (the normal, legitimate monthly
billing-cycle write path) nor `PaymentService::create()` ever calls `$customer->touch()` or
`->save()` on the customer row itself when a manuscript is written. The practical result: **a
customer's cached arrears/credit figures can only ever refresh on mobile if that customer's own row
was independently touched for some unrelated reason** (e.g. a direct edit) — a manuscript being
created, recalculated, corrected, or (this incident) deleted is invisible to every future delta
pull, indefinitely, regardless of how many times "Sync now" is tapped.

**This is a generalizable bug, not merely "raw SQL bypasses everything, expected."** It would
already misfire on a completely normal `manuscript:calculate` run for any customer whose own row
isn't otherwise touched that cycle — today's direct-SQL incident is the trigger that surfaced it,
not a special case of it. The direct-SQL angle does compound it further in one respect worth noting
for the record: a raw `DELETE` also can't fire any Eloquent `saved`/`deleted` event, so even a
`$touches` relationship (which relies on Eloquent's save lifecycle) would NOT have caught this
specific incident either — only an explicit reconciliation step (a tombstone, or a full re-pull)
can. That reinforces the fix chosen below (see "what was fixed").

**Investigated and ruled out as the sustained cause (real, but self-healing, mechanisms):**
- `ManuscriptService::list()`/`summary()` (used by `app/manuscript.tsx`, which is otherwise
  correctly live/uncached on-device per §13) ARE server-side `Cache::remember`'d, 10-minute TTL,
  keyed by period/branch/filter hash — and `forgetSummaryCache()` is only ever called by
  `ManuscriptCalculate`, never by anything resembling today's raw SQL path, so a request served
  from cache in the ~10 minutes straddling the delete could have returned pre-delete totals. Given
  the report landed roughly an hour after the delete, this TTL has self-healed several times over by
  now and is not the sustained cause — but it is a real, latent version of the exact "server-side
  cache invisible to a direct-SQL change" risk this investigation was asked to check for, and would
  bite again, briefly, on any future direct DB intervention within a fresh 10-minute window.
- `CustomerService::findOrFail()` (`customers:show:{uuid}:{branch}`, 60s TTL, eager-loads
  `latestManuscript`) — same shape, shorter TTL, same conclusion: self-healed by now, not the
  sustained cause, but a real instance of the same class of risk for the ~60s after a future direct
  DB write.
- Confirmed `SyncService::pull()`'s `customers.deleted` is always `[]` (no tombstone mechanism
  exists — `Customer` has no `deleted_at`, documented inline in the code itself) — not relevant here
  since no customer rows were deleted, only manuscript rows, but confirms the codebase's delta-sync
  design has no deletion-reconciliation story at all beyond what this section's fix adds for the
  arrears-figure case specifically.

**What was fixed (mobile-only, contained, low-risk):** No backend code was changed — the deeper fix
(making manuscript writes touch the owning customer's `updated_at`, or a real tombstone/reconciliation
mechanism) is real future work but touches the shared recalculation path multiple write flows depend
on, which is a bigger, riskier change than this report's scope, and per this session's constraints a
backend test suite is currently in concurrent use by another process. Added a **"Force full
refresh"** action instead, addressing the actual reachable gap: mobile had no way at all to escape a
watermark-blind customer, not even by logging out (deliberately preserves local data/last_sync_at —
see §7) or force-closing the app (SQLite `sync_meta` persists across restarts).

- `mobile/src/db/syncMeta.ts` — new `clearLastSyncAt()`, a plain `DELETE FROM sync_meta WHERE
  key = 'last_sync_at'`.
- `mobile/src/sync/SyncManager.ts` — new `forceFullResync()`: clears the watermark, then calls the
  existing `syncNow()` unchanged. With no watermark, `SyncService::pull()`'s `$sinceAt` is null, so
  `upsertedCustomers()`'s `->when($sinceAt, ...)` clause is skipped entirely and every zone-scoped
  customer is returned regardless of `updated_at` — the same shape as a first-login sync. Safe to
  call anytime: `upsertCustomers()` (`src/db/customers.ts`) is a plain `ON CONFLICT DO UPDATE`
  upsert, never delete-then-insert, and a zone is only low hundreds of customers (§2's stated
  scale), so this is a cheap request, not a heavy resync. Queued outbox items (payments/
  expenditures/complaints) and everything else in the same `syncNow()` cycle are untouched — only
  the customers-pull watermark is reset.
- `mobile/app/sync-status.tsx` — a second, visually distinct (`variant="secondary"`) button below
  the existing "Sync now," labeled "Force full refresh," with an explanatory hint line ("Re-downloads
  every customer's bill and arrears figures from the server, even ones 'Sync now' would skip. Use
  this if a customer's balance looks out of date."). Deliberately NOT merged into the existing "Sync
  now" button or made to run automatically — a full customer re-pull on every ordinary sync tick
  would be needless network/battery cost at this app's normal cadence (§2's four triggers already
  fire frequently); this is an explicit, occasional escape hatch for exactly this failure mode, not
  the new default. Needed its own local `forceRefreshing` state rather than reusing
  `liveState.phase === 'syncing'`, since `syncingProgress` (which drives that phase) is only ever set
  inside `push()` for queued outbox items — a customers-only pull is invisible to it.

**The immediate action for the product owner, right now:** Open the app → tap the sync-status strip
(persistent on every screen, §5) → **Sync Status** sheet → **"Force full refresh."** This is now a
real, implemented action, not a hypothetical one — confirmed by writing and type-checking the code
above, not just designed. Logging out/in and force-closing/reopening the app were both checked and
do NOT help (see above); a plain "Sync now" tap also does NOT help for this specific failure mode
(it reads the same unchanged watermark) — the new button is the only correct answer today, which is
exactly why it was added rather than only documented.

**Verification:** `cd mobile && npx tsc --noEmit` — clean except the two pre-existing
`src/api/devices.ts` errors (unrelated, unchanged, called out as expected in every prior pass's own
verification section too). `npm test` — 100/100 passing, unchanged (no `src/utils/*` pure-function
logic was added by this fix — `syncMeta.ts`/`SyncManager.ts`/`sync-status.tsx` changes are DB
helper/orchestration/UI only, same shape as several prior additions in this file that also added no
new test coverage).

**Deliberately NOT done:** No backend PHP was changed (see above — the real root-cause fix is
future work, flagged here, not silently left undiscovered). No change to
`ManuscriptService`/`CustomerService`'s `Cache::remember` TTLs or keys — both were investigated and
ruled out as the sustained cause (see above); shortening either TTL further would be a blind
defensive change with a real perf cost (both exist specifically to absorb read load) for a risk this
report found to be minor and self-healing, not the reported bug. No automatic/periodic full resync
was added — only an explicit, agent-initiated one, per the reasoning above.

**Procedural note, separate from the code fix:** the underlying trigger — raw SQL run directly
against production-shaped data outside every app write path — remains something no client-side sync
logic can fully defend against in general (a raw `DELETE` fires no Eloquent event, so even a
`$touches` fix wouldn't have caught this specific incident, only reduced how often manual
intervention is needed for the *normal* recalculation case). Direct DB intervention should be
followed by a deliberate resync signal to affected clients (this new "Force full refresh" button is
now that signal for mobile) rather than assumed to be invisible-and-fine — this is a process
recommendation, not something further code can fully substitute for.
