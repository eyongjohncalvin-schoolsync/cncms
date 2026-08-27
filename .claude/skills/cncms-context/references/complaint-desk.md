# Complaint Desk — Design Spec

Status: **Design, not yet implemented** | Owner ask: a place where agents/workers channel
complaints, broadcast as notifications, escalating automatically if unresolved (48h → emergency,
broadcast to all staff, with a human-gated path up to a new Investor tier). Built from a 4-expert
deliberation (data model/workflow, notification infrastructure, escalation engine + investor tier,
UX/urgency-signaling), all with web research. Depends on `references/task-scheduler.md` (the
escalation clock needs a real recurring tick, which didn't exist before that design) and
`references/in-app-notifications.md` (the actual delivery mechanism this feature broadcasts
through). Build order: task scheduler → in-app notifications → this feature, web first, then the
mobile counterpart in §7.

---

## 1. What a "complaint" is — resolved

**Both.** An agent/worker can log an internal operational issue (system/process problems — e.g.
"my zone's customer list won't sync," "the manuscript numbers look wrong") or a customer complaint
relayed on the customer's behalf (agents are the only staff with direct customer contact — this app
has no customer self-service portal). One `category` field distinguishes them and drives real
behavior, not just a label:

| | `operational` | `customer` |
|---|---|---|
| Escalation ceiling | All staff **+ Investor tier** (human-gated, see §3) | All staff only, no investor |
| `customer_id` | must be null | must be set |

Two values only. No sub-taxonomy, no admin-editable category list — hardcode as an enum, matching
how `payment_verifications.status` is already a fixed Postgres enum in this codebase, not an
admin-editable lookup table.

## 2. Data model

`Complaint` (tenant-scoped, dual-key `id`/`uuid`, `#[Fillable]`, `use Auditable`):

| Column | Notes |
|---|---|
| `category` | `enum('operational','customer')` — see §1 |
| `title`, `description` | `description` required |
| `urgent` | boolean, default `false` — a fast-path flag, NOT a graded priority scale (see §3.4 for why the scale was deliberately dropped). Not self-selected by the submitter — see §7's UX note |
| `status` | `enum('open','in_progress','resolved')` — deliberately excludes "escalated" as a status value; escalation is a time-based fact tracked separately (`escalated_at`), not a workflow state, so a complaint can be `in_progress` AND escalated at once |
| `submitted_by` | cross-schema FK to `public.users.id`, same raw-`DB::statement` pattern as `expenditures.user_id` — not fillable, set from `auth()->id()` |
| `assigned_to` | nullable, same cross-schema FK pattern — optional metadata only, nothing behaves differently based on whether it's set; a human "I've got this" signal, not a routing engine |
| `customer_id` | nullable FK to `customers`, required iff `category='customer'` — enforced in `StoreComplaintRequest`, not a DB constraint |
| `zone_id` | nullable FK to `zones`, auto-derived at creation (customer's zone for `category='customer'`, submitting agent's own zone for `category='operational'`) — exists purely to scope the duplicate-guard in §4, never user-entered |
| `resolved_at`, `resolved_by`, `resolution_notes` | `resolution_notes` required (non-empty) by the resolve `FormRequest` even though the column is nullable — nullable so a reopen can clear it |
| `escalated_at` | set once by the escalation checker the first time the 48h threshold fires; re-escalation logic checks this is still null before re-firing at higher levels — see §3 |
| `duplicate_of_id` | nullable, self-referencing FK — see §4 |

Composite index `(status, created_at)` for the escalation checker's sweep query.

## 3. Lifecycle, escalation, and the Investor tier

**Who can create**: all five roles, no gate — the one feature `worker` gets genuine capability in.
**Who can resolve/reopen**: `super`/`admin`/`manager` only — never the submitter (closes the
obvious self-resolution/self-reopen gaming path, the same standing caution
`references/rbac-permissions.md` already documents from this session's own history).
**Visibility**: everyone sees everything, no per-zone filtering — deliberate: at this staff size, a
complaint visibly sitting open creates real social pressure to close it before automatic
escalation, which is a legitimate small-team control, not an oversight.

**The 48-hour clock**: starts at `created_at`, runs continuously, and the *only* thing that stops
it is `resolved_at` being set. **`in_progress` does NOT pause it.** This is deliberate, not an
oversight: if a status change paused the clock, one click in minute one would permanently suppress
escalation on something nobody ever touches again — defeating the entire point of a feature meant
to catch things falling through the cracks. A complaint reopened after being wrongly marked
resolved immediately shows as already-overdue (the clock is never reset by reopening) rather than
getting a fresh 48-hour grace period — this removes any incentive to rubber-stamp "resolved" just
to silence the clock.

**Escalation levels** — 4 fixed levels, thresholds configurable per-tenant (default hours shown),
audience and level *count* are NOT admin-configurable (matches this app's "small fixed tiers, not
open-ended configurability" ethos, and mirrors real incident-tool precedent — PagerDuty/Opsgenie
both use small fixed staged tiers, not arbitrary admin-defined ones):

| Level | Default threshold | Audience |
|---|---|---|
| 0 — Assigned | immediate | assignee + their manager, if set |
| 1 — Team escalation | 24h | all `super`/`admin`/`manager` |
| 2 — Full staff emergency | **48h** (owner's exact spec) | every role: `super`/`admin`/`manager`/`agent`/`worker` |
| 3 — Investor notice | armed at 48h, **not auto-fired** | investors (`is_investor = true`), sent only after a human clicks "Notify Investors" |

**Level 3 requires a human decision — this is the alert-fatigue safeguard, and it's load-bearing,
not optional polish.** Automatic time-based escalation stops at Level 2. At the 48h mark, a
prominent "Notify Investors" action becomes visible to `super`/`admin` on the complaint, but the
actual investor notification only sends when a person clicks it. Reasoning: the owner's own
framing of the investor tier is about transparency/accountability — investors seeing *meaningful*
signals, not routine backlog noise. A purely automatic path to investors means every complaint
that happens to sit unresolved 48 hours (which will include ordinary workload lulls, not just
real problems) trains investors to ignore these messages, exactly the failure mode real incident-
management research identifies as the primary cause of alert fatigue from ungated top-tier
escalation. This keeps the owner's literal spec intact — the highest escalation level does reach
investors — it's *armed* automatically exactly as specified, just *fired* by a person.

**Investor tier itself is documented in `references/rbac-permissions.md` §7 (added alongside
this feature)** — it's fundamentally an RBAC extension (one additive flag/OR-clause on
`ReportPolicy`, same shape as the existing `can_record_payments` case), not complaint-desk-specific
plumbing, so its full design lives there. Summary: `tenant_users.is_investor` boolean, NOT a 6th
role value, enforced entirely in `ReportPolicy::view()`, a distinct `InvestorLayout.tsx` mirroring
`LandlordLayout.tsx`'s existing shape. **From the owner's and the investor's own perspective this
is just a normal user account** — same login form, same tenant login flow — the flag is invisible
internal plumbing, never a separate portal or credential system.

**Trigger mechanism**: the `complaint_escalation_check` task type on the generic scheduler
(`references/task-scheduler.md` §5) — runs every tick (15 min), compares each open complaint's
elapsed time against the threshold table, fires the appropriate level's notification the first
time it's newly crossed. Idempotency requires a `complaint_escalations` log table (`complaint_id`,
`level`, `escalated_at`, `notified_tenant_user_ids`) rather than a single column on `Complaint` —
this log is also what makes de-escalation (below) possible without re-deriving "who was actually
notified" from nothing.

**Resolution/de-escalation notice**: when a complaint resolves, query `complaint_escalations` for
that complaint, collect the distinct audiences actually notified across whatever levels were
reached, and send one "resolved" notice to exactly that accumulated audience — never to people who
were never escalated to.

**Alarm message content**: fixed templates per level (4 total), not admin-editable free text.
Reasoning: proportionate to 4 fixed levels, avoids validation/injection surface and per-locale
duplication (this app supports multiple languages) for a team this size with no expressed need for
custom wording, and mirrors the real precedent already established in
`references/bill-notifications.md` §2 (WhatsApp requires pre-approved templates for exactly this
reason). Revisit only if a real need for tenant-specific wording emerges later.

## 4. Duplicate/spam guard

Soft, non-blocking, proportionate to a handful of agents — not a dedup engine:

1. **At submission**: before the form is filled in, show existing open complaints matching the
   derived `zone_id`/`category` (operational) or `customer_id` (customer), opened in the last 7
   days, inline: *"There's already an open complaint for this — view it, or still file a new one?"*
   Never a hard block.
2. **After the fact**: a manager-only action links a genuine duplicate via `duplicate_of_id`. A
   linked duplicate is excluded from its own escalation broadcast (rides on the original's clock
   instead) but stays fully visible and audit-tracked — a link, not a delete. This link is the real
   noise-reduction backstop; the submission-time warning is best-effort triage only.

## 5. In-app notification integration

This feature is the primary early consumer of `references/in-app-notifications.md`'s system.
Hooks: `Complaint` creation with `urgent=true` → immediate broadcast (in addition to, not instead
of, the normal 48h clock still running underneath); each escalation level crossing → broadcast to
that level's audience per the table in §3; resolution → the de-escalation notice above. The
distinction between passive "read" and active "acknowledged" (that system's §5) matters most here:
an emergency (Level 2+) broadcast should track real acknowledgment, not just whether a bell icon
was clicked — coordinate the exact acknowledge-trigger (viewing the complaint page? a dedicated
button?) with that doc's open question, resolved as: **a dedicated "Acknowledge" button**, distinct
from viewing/dismissing (see §7's UX note — dismiss and acknowledge must never be the same action).

## 6. Web UI

**Navigation**: un-gated — visible to every role, same tier as Dashboard/Customers/Payments (not
role-gated like Settings/Resources/Audit). The feature's entire premise is universal visibility;
hiding the nav entry from any role would directly contradict that. One route (`/complaints`)
serves both audiences via server-side role-based view selection (submission-first view for
agents/workers, dashboard/list view for managers/admins) — same convention `ReportController`
already established for its own role-based tiers.

**Nav color**: a new `fuchsia` entry in `AppLayout.tsx`'s `NAV_ACCENTS` — every existing key
(blue/indigo/teal/green/amber/purple/pink/cyan/slate/red/orange) is claimed, and reusing red or
amber specifically would make the icon look alarming even when the queue is empty, which
contradicts the "calm until actually urgent" rule this app already established for the mobile sync
strip. One deliberate exception: a small nav corner badge dot, fuchsia/slate normally, **red only
when a complaint is actually escalated** — the one place the nav item is allowed to borrow severity
color, because an active emergency must be visible even from a screen the user hasn't opened.

**State visual language** — reuses the existing 5-tone `Badge` component (`slate/blue/yellow/red/
green`) with zero new component work:

| State | Badge tone | Notes |
|---|---|---|
| New/routine | slate | flat |
| In-progress | blue | flat |
| Approaching 48h (36h+) | yellow | amber left-border + countdown, same register as the mobile sync strip's "attention, not broken" |
| Escalated | red | red-tinted card, 4px red left-border (reuses the existing flash-message convention), `animate-pulse` dot (copy the exact pattern already in `Settings/Company.tsx`'s "Active" indicator). Pins to top of any list regardless of sort |
| Resolved | green | muted, collapses into a "Resolved" section |

**Emergency broadcast UI**: not a modal on every page load (trains people to click through
blindly). A **persistent top-of-shell banner** (above the header, survives navigation, unlike the
existing one-shot flash messages), with a labeled **"Acknowledge" button — never an X-to-dismiss**,
since dismiss ≠ acknowledge is the entire point (mirrors PagerDuty/Opsgenie's explicit separation:
acknowledging claims ownership and is tracked; dismissing is not). Micro-copy clarifies scope:
"Acknowledging lets [manager] know you're aware — it doesn't resolve the complaint." **One
exception**: the first moment an escalation fires while a user is actively in-session, use a single
one-time modal interrupt to guarantee live visibility, then downgrade to the persistent banner for
every subsequent load until acknowledged.

**Dashboard/list view** (managers/admins): a `StatCard` row reusing existing tones — Open (slate),
Approaching Deadline (yellow), Escalated/Overdue (red), Resolved This Week (green). List
sortable/filterable by status/age; escalated items always pin to the top regardless of chosen sort.

**Submission form**: title (required), category chips (faster to tap than a dropdown), description
(optional, collapsed textarea by default, mirroring the mobile Record Payment "reference only"
collapsible pattern), optional photo. Location/customer/zone context auto-attached silently from
session, never asked. **No self-declared urgency/priority field** — letting the submitter pick
"urgent" is itself the mechanism of alert fatigue; actual urgency is derived by the escalation
clock, never guessed at write time (the `urgent` boolean in §2 is a deliberate *fast-path*
override for the rare "can't wait 48h" case, exposed as a clearly-separate, clearly-labeled toggle
— not a routine severity picker every submission touches).

## 7. Mobile (build after the web version is complete and tested)

Add to the existing React Native app (`references/mobile-app-react-native.md` is the parent spec
for everything about that app's stack/conventions — this section only covers what's specific to
Complaint Desk; follow that doc's established patterns for everything else: `expo-sqlite` local
cache, the offline-queue pattern already used by Record Payment/Record Expense,
`local_uuid`-keyed sync entries, the amber-not-green "saved, will sync" confirmation state).

- **Not a 5th bottom tab.** The mobile app's 4-tab structure (Home/Customers/Record Payment/
  History) is a deliberate constraint; a 5th tab dilutes the "one-handed, glanceable" design this
  session already committed to. Instead: a secondary "Log a Complaint" CTA card on the Home screen,
  next to the existing Record Expense entry point — same "one tap deeper, not top-level" pattern.
- **Submission-only is the v1 mobile scope.** Browsing/managing the full complaint list, resolving,
  assigning, and the manager dashboard stay web-only for v1 — those are office tasks, not something
  agents need mid-route. Revisit only if real usage shows agents need to check complaint status
  from the field.
- **Submission form**: same fields as web §6 (title, category chips, optional description/photo,
  no self-declared urgency), reusing the exact camera-only receipt-photo pattern already built for
  Record Payment/Record Expense (`expo-image-picker`, no gallery picker). Offline-safe: writes to
  local SQLite with `sync_status='queued'` immediately, same amber "Saved · will sync" confirmation
  the payment/expense screens already use — never a different visual language for "saved but not
  yet synced" than what agents already trust from those screens.
- **Emergency broadcast treatment — deliberately DIFFERENT from the mobile app's existing "never a
  blocking modal" rule for sync errors.** Do not reuse that precedent by default; the two cases are
  categorically different, not just cosmetically similar. A sync error is routine/expected —
  blocking there would create the exact alarm fatigue the mobile app's design was built to avoid.
  A 48h emergency complaint broadcast is rare by design (should almost never fire if the escalation
  engine and its UI are working) and the owner explicitly required it be unmissable — rare +
  high-stakes justifies an interrupt, frequent + routine does not. Concretely: **one full-screen
  interrupt on next app open**, explicit Acknowledge button (not swipe-to-dismiss), then downgrades
  to a persistent red banner reusing the existing sync-status-strip's screen real estate (stacking
  above or replacing it while unacknowledged — users already know "top strip = system status"). A
  small red dot on the Home tab icon is secondary reinforcement only, never the primary mechanism —
  a bare badge is exactly what gets learned-to-ignore per the alert-fatigue research already cited
  in `references/mobile-app-react-native.md`.
- **Notification delivery on mobile**: piggybacks on the existing `SyncManager` pull cycle per
  `references/in-app-notifications.md` §1 — no second real-time channel. Office staff on web are
  the primary "everyone must see this now" audience for most broadcasts; if agents specifically
  need faster delivery for the emergency tier, tighten the existing periodic-pull interval rather
  than building new transport.
- **Investor tier has no mobile presence.** Investors are a web-only, report-viewing persona; there
  is no reason for an investor login to exist in the field-agent mobile app at all — don't build
  one.
