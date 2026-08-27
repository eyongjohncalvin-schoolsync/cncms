# In-App Notifications — Design Spec

Status: **Design, not yet implemented** | Owner realized, while asking for the Complaint Desk
feature, that this app has no internal notification system at all. This doc is the foundational
piece `references/complaint-desk.md` broadcasts through — build this before that feature's
broadcast/escalation pieces, though the base `Complaint` CRUD can be built in parallel.

**Not the same system as `references/bill-notifications.md`.** That's a separate, already-designed
system for notifying *customers* about bills via SMS/Email/WhatsApp (external, Twilio-based). This
is internal, staff-facing, in-app only (agents/workers/managers/admins/supers, plus investors —
real system users with real accounts). Some infrastructure *ideas* rhyme (a delivery-channel
abstraction) but the audiences, channels, and urgency models are genuinely different — don't merge
them into one system.

---

## 1. Delivery mechanism — polling, not real-time push

**No broadcasting infrastructure exists today** (verified, not assumed — no Pusher/Reverb/Echo
references anywhere in this codebase, `BROADCAST_CONNECTION=log` in `.env`, no
`config/broadcasting.php`). Standing up real-time push (self-hosted Reverb, or Pusher-as-a-service)
is genuine new ops surface — a process to run and monitor, channel-auth wiring, tenant-scoping the
socket auth — for a team of a handful of users where 15-30 second delivery latency is very likely
fine. **Recommendation: Inertia's `usePoll()`** (already a documented, supported pattern in this
codebase — see the `inertia-react-development` skill), at ~20-30s interval, scoped with
`only: [...]` so each tick is a cheap partial reload, not a full page re-fetch. Explicitly rule out
Reverb/Pusher/Echo for v1 — revisit only if a genuine need for sub-second delivery emerges later
with real justification, not built speculatively now.

**Mobile**: don't build a second real-time channel — see §6.

## 2. Data model

Two tables, splitting the event from per-recipient state (reasoning in §3):

**`notifications`** (one row per logical event):

| Column | Notes |
|---|---|
| `id`, `uuid` | dual-key, UUID v7 |
| `type` | dot-namespaced string, e.g. `complaint.escalated`, `complaint.assigned` — mirrors this app's existing `namespace:action` artisan-command convention |
| `severity` | `enum('info','warning','urgent','emergency')` — drives display treatment |
| `title`, `body`, `link` | `link` is the deep-link path, e.g. `/complaints/{uuid}` |
| `source_type`, `source_uuid` | nullable, points back to the originating entity independent of `link`, so "all notifications about complaint X" stays queryable even if URL shapes change later |
| `broadcast_scope` | `enum('user','role','all')` — explicit discriminator, not inferred from nullability |
| `recipient_user_id` | nullable, set when `broadcast_scope='user'` |
| `recipient_role` | nullable string, set when `broadcast_scope='role'` — **investors addressed as `recipient_role='investor'`, since they're real users with a real (if minimal) tenant_users row, same query shape as any other role-targeted broadcast** (this was an open question raised mid-deliberation and resolved by the owner directly: investors are ordinary users, not a separate loginless audience — see `references/rbac-permissions.md` §7) |
| `created_at` | |

**`notification_recipients`** (per-user state, lazily materialized — not written at broadcast time
for every matching user):

| Column | Notes |
|---|---|
| `notification_id`, `user_id` | |
| `read_at` | nullable, passive — set only when actually opened in the UI, never merely because a poll tick delivered it |
| `acknowledged_at` | nullable, **active**, genuinely separate column from day one — see §5 |

## 3. Broadcast fan-out — lazy per-recipient state, not eager duplication

"Unread for me" is computed as `notifications WHERE (audience matches me) AND NOT EXISTS
(notification_recipients row for me)` — correct for any currently-relevant user with zero special
casing, which matters specifically because role membership changes at this team's size (someone
promoted or hired after a broadcast fired still correctly sees it, no backfill write needed). This
mirrors this codebase's existing event/state separation instinct (`audit_logs` is an immutable
event log; state is computed elsewhere, never duplicated into the log itself). At this app's actual
scale, the query-cost difference against simpler eager fan-out is negligible — this is a tie-break
on correctness/cleanliness, not performance; eager fan-out (closer to Laravel's own built-in
notification idiom) is a legitimate, simpler fallback if a build agent finds the lazy-state join too
awkward in practice.

**Don't adopt Laravel's `Notification` facade/`database` channel wholesale.** That system is built
around "send via N channels using a notification class per event type" — unneeded ceremony when
this app only ever needs one channel, and its polymorphic `notifiable`/JSON-blob idiom sits outside
this codebase's established Controller→FormRequest→DTO→Service→Repository→Policy→Resource
layering. Build a bespoke `NotificationController`/`Service`/`Repository`/`Policy`/`Resource` set,
registered in `RepositoryServiceProvider` alongside the existing `ManuscriptRepositoryInterface` —
clone that feature's exact shape, don't deviate into a Laravel-native idiom the rest of the app
doesn't use.

## 4. Web UI — two distinct treatments, not one generic component

**Routine (bell + dropdown)**: bell icon in `AppLayout.tsx`'s header (existing `auth.user` cluster,
near `LanguageSwitcher`/`RoleBadge`). Badge = unread count. Dropdown panel (Headless UI, already a
project dependency) lists recent notifications, severity-colored left border reusing the same
`border-l-4` language already established by the existing `flash.success`/`flash.error` banners.
Click → Inertia `<Link>` to `link`, marks read. "Mark all read" action. Driven by
`usePoll(20000, { only: ['notifications'] })`.

**Urgent/emergency (banner, not a bell item)**: a full-width, critical-colored banner above
`<main>`, shown whenever the current user has an unacknowledged `severity: 'emergency'`
notification. This is the shared primitive `references/complaint-desk.md` §6's emergency-broadcast
UI builds on — see that doc for the exact acknowledge-button treatment (never dismiss-to-hide).

## 5. Read vs. acknowledge

Kept as genuinely separate columns from day one, not collapsed into one. For routine notifications
these naturally collapse to the same moment (opening it IS acknowledging it — no further action
needed). For emergency broadcasts they must not: the real precedent is PagerDuty/Opsgenie's model
— acknowledging claims ownership and halts further escalation, distinct from merely having seen a
badge. **The exact acknowledge-trigger is resolved in `references/complaint-desk.md` §6: a
dedicated "Acknowledge" button, never inferred from a click-to-dismiss or from opening the linked
page.** The schema here is built so that decision slots in without a later migration.

## 6. Mobile

**Don't build a second real-time channel.** Extend the existing `GET /sync/pull` response
(`mobile/src/sync/SyncManager.ts`) with a `notifications` block, consumed alongside the already-
synced `customers`/`payments` data. This is already triggered on app-foreground, network-reconnect,
a 5-minute periodic timer, and immediately after local writes — coarser than web's 20-30s target,
but appropriate: office staff on web are the primary "everyone must see this now" audience for most
broadcasts, agents in the field are secondary for routine notifications. If the escalation tier
specifically needs faster mobile delivery, tighten `SyncManager`'s periodic interval or add a
foreground-triggered check — don't build a new transport for it.

Display treatment on mobile (routine bell-equivalent vs. the emergency interrupt) is specified in
`references/complaint-desk.md` §7, since that's the feature actually driving urgent notifications
in this app's first release — this doc only covers the underlying delivery/data mechanism.

## 7. Explicitly out of scope for v1

No pub/sub message bus. No per-user notification preferences/settings UI (v1 is "everyone gets
everything relevant to their role," not configurable opt-outs). No Web Push API (polling while the
tab/app is open is sufficient — nothing here needs to reach a closed tab or backgrounded app). No
digest/email-summary system (adjacent to the separate bill-notifications work, out of scope here).
**Retire the dormant, unused `alerts` table/`Alert` model** (found during this deliberation —
`database/migrations/tenant/2026_08_19_090604_create_alerts_table.php`, `App\Models\Alert`: just
`name`+`message`, no recipient, no read state, never wired to anything in `app/`) as part of this
work, rather than leaving it as a second, half-formed notification concept alongside the real one.
