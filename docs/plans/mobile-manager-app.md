# Mobile app for managers — daily actions, alerts, new look

**Owner ask (2026-10-31):** bring the web's daily work to the phone so
admins and managers use the app all day — even through power cuts (phones
run on battery and mobile data). Focus on what needs attention daily:
approvals, cash, complaints, assigning and alerting technicians. Managers
get alerts on actions to take. Design: the agreed combination (canvas
"CNCMS mobile app — design directions"): **C's inbox structure** (Now /
Today / FYI, decide from the list, a few seconds to undo), **A's colours**
as the normal look, **B as dark mode** (follows the phone), **B's
press-and-hold** for large amounts. Plus a light/dark switch on the web.

## Principles

1. **Phone = decide and act; web = set up and analyse.** Imports, bill
   printing, settings, the role→permission matrix, legal and big reports
   stay on the web. An inbox item the phone can't do says "open on the
   website".
2. **Same rules everywhere.** Every phone action calls the same service and
   the same policy as the web (`TenantContext::can()`, `VoucherService::
   canDecide()`, maker ≠ checker). No phone-only shortcuts.
3. **Decisions need a connection.** Reading works offline from the last
   sync; approving does not queue offline (two managers could decide the
   same item).
4. **Alerts must stay worth reading.** Urgent = sound, stays until handled;
   the rest arrives silently; a morning digest; escalation to the owner if
   nobody acts; quiet hours that emergencies break through.

## Waves

1. **Manager inbox.** `GET /api/v1/inbox`: everything waiting for *this*
   user, permission-filtered, with severity (now / today / fyi). Decide
   from the phone: vouchers (approve/reject), arrears adjustments
   (approve/reject), payment reversal requests (reverse/decline),
   complaints (assign). Shown, opened on the web: field activations,
   opening balances, payments to verify, cash to receive. Mobile: an Inbox
   tab for anyone with something to decide; item screen; approve sends
   after a 4-second undo window.
2. **Alerts.** Push for every inbox type to the people who can act; urgent
   vs quiet; escalate to the owner after N hours untouched; morning digest;
   quiet hours per person; technicians alerted when a complaint is assigned.
3. **Complaints and technicians.** Complaint desk for managers (all open
   complaints, assign / reassign a technician, resolve); "My jobs" for
   technicians.
4. **Cash on the phone.** Receive agents' cash, accept transfers, close the
   day, shortages and cash alerts.
5. **Today and staff.** Collections, zones and money balance (view only);
   deactivate a user, unblock a sign-in, change a person's role.
6. **New look.** A's colours as theme tokens, dark mode following the
   phone, press-and-hold for amounts above a threshold, app lock (PIN /
   fingerprint — needs a new Expo package: owner approval first).
7. **Web light/dark switch.** Light / Dark / Match my device in the header,
   remembered per user; colours swapped centrally (Tailwind 4 variables),
   then touch-ups.

Each wave: backend + tests, mobile screens, one commit, pushed to `main`
and `preview`. Mobile builds go out per `deploy/MOBILE-DISTRIBUTION.md`.
