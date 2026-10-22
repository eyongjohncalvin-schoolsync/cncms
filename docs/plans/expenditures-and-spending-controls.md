# Expenditures: one cash-out point, month close, spending cap

**Owner ask (2026-10-22):** the expenditures index only has Delete (no edit);
it should be scoped to the current month with past months archived; and there
must be a threshold so the company cannot overspend or run a deficit, like the
bons (vouchers) limited to 50k. Designed from three independent brainstorms
(money/controls, UX/workflow, adversarial gaps) and the owner's answers.

Short-lived branch → `main` + `preview`. Tests only on `cncms_testing`, one
`phpunit` process at a time. Never touch the live `cncms` database.

## Owner decisions (2026-10-22)

| # | Decision |
|---|---|
| E1 | **Every cash-out goes through a bon.** No separate direct/petty expenses — one single point for money leaving. Expenditures are created only when a bon is paid or settled. |
| E2 | **Monthly spending cap**: a fixed amount set in Settings (next to the 50k bon limit). |
| E3 | Spending that would go over the cap needs **every investor's approval** (owner when there are no investors) — the existing stakeholder tier. |
| E4 | Keep it simple: **no exempt categories**; salaries, rent and supplier fees count against the cap like everything else. |
| E5 | Per-category budgets **warn only** (amber 80%, red 100%). |
| E6 | **Month close** automatically on the **5th** of the following month (Cameroon time); only the **owner** reopens, with a reason. Closed months never change; corrections are posted in the current month, referencing the original. |
| E7 | Edits and voids: **admin and owner only**, reason required; the person who requested/recorded it cannot change it afterwards. |
| E8 | **Daily bon limit on by default: 100,000 FCFA**, counted by the day a bon is **paid** (closes the split / future-date loophole). |
| E9 | **Landlord impersonation is blocked from every money action** (payments, verification, bons, cash, installation charges, expenditures). |
| E10 | **Company-wide** caps and budgets (no per-branch for now). |

## Confirmed bugs fixed here

- Expenditure **Delete is a hard delete**; deleting a bon's expenditure orphans
  the paid bon (FK `nullOnDelete`) and silently rewrites past P&L.
- Mobile sync (`SyncController::authorizeSync`) only checks `manuscripts.view`;
  `SyncService::pushExpenditure` never checks the create permission.
- `SyncService::uploadReceipt('expenditure')` lets any sync user replace any
  expenditure's receipt photo (evidence tampering).
- `voucher_daily_limit` is NULL by default and the daily total is keyed on the
  requester-chosen `needed_on`, so payouts can be split or future-dated past the
  stakeholder tier.
- A bon (notably an advance) without a category writes **no** expenditure: cash
  leaves the till but never reaches the P&L.
- Direct expenditures write no cash movement (cash book ≠ P&L) — removed by E1.
- Several "today" defaults use UTC instead of Cameroon time (wrong month between
  00:00 and 01:00 WAT on the 1st).
- Budget actuals joined by category *name*; edits can't clear text fields; bon
  expenditure notes print a blank payee; edits can rewrite the entry's source.

## Build

1. **Single cash-out point (E1).**
   - Remove direct expenditure creation: web Create page/route, API store, and
     the mobile direct create path. Existing direct expenditures stay as
     **legacy** rows (read-only except void by admin/owner with reason).
   - Expenditures get `voucher_id` (FK, restrict on delete) and `source`
     (`voucher` | `legacy`). Only `VoucherService` pay/settle writes them.
   - **Category required on every bon**, including advances (at request, or at
     settlement for advances), so every franc out reaches the P&L.
2. **Mobile "Record expense" → "Request a bon".** Offline-queued sync item
   (idempotent `local_uuid`) creating a bon request by the agent, with optional
   receipt photo (upload allowed only by the requester, only while pending).
   Option **"already spent from my collections"**: once approved, the bon is paid
   at that agent's handover (listed on the remittance form; the cashier pays it
   out of the received cash in the same step), so the cash count still matches.
   Gate with the bon-request permission, not `manuscripts.view`.
3. **Daily bon limit (E8):** default 100,000 for every tenant (migration sets it
   where NULL); the tier is re-checked at approve **and pay** using the pay date;
   checks run under a lock so concurrent approvals can't race past the limit.
4. **Monthly cap (E2–E4):** `companies.monthly_spending_cap` (Settings → Cash
   controls). Committed spend for the month = paid/settled bon expenditures +
   approved-but-unpaid bons. If committed + this bon > cap → stakeholder tier
   (`above_monthly_cap` reason), re-checked at each decision and at pay, under a
   lock. Shown on the bon form ("this takes March to 104% of the cap").
5. **Budgets (E5):** Resources → Budgets: categories × month grid, copy from last
   month, total vs cap; permission `budgets.manage` (owner, admin, manager).
   Warnings on the bon form, the expenditures header and the dashboard.
6. **Deficit warning:** month's expenses vs month's verified income
   (subscription + installation; technician fees excluded) — red indicator on
   the expenditures header and dashboard, line in the daily alerts digest. Never
   blocks.
7. **Expenditures index:** defaults to the current month; header strip (spent,
   cap, budget, income so far, net — red on deficit, per-category chips); month
   picker → past months as a read-only archive with a "closed" badge; filters
   (category, source, status, recorded by); export; each row links to its bon.
8. **Expenditure detail:** history timeline from the audit log; admin/owner may
   re-categorise or fix description/notes in an **open** month with a reason
   (E7; never the bon's requester); amounts are never edited here.
9. **Corrections:** a paid bon can be **corrected** by admin/owner with a reason:
   money returned → cash-in movement to the till + negative adjustment
   expenditure in the current month; underpaid → a new bon. **Void + reason**
   replaces Delete everywhere (voided rows stay, excluded from totals); no hard
   delete.
10. **Month close (E6):** scheduled job on the 5th (WAT) closes the previous
    month and snapshots its totals; closed months are read-only for re-categorise
    / void; reopen = owner only, reason, audited, alerted. Late items land in the
    current month with `relates_to_period`.
11. **Impersonation (E9):** every money write refuses while a landlord is
    impersonating (reuse the `not-impersonating` middleware on the money routes;
    the API never impersonates).
12. **Cameroon time** for every "today"/month default (single helper);
    budget actuals by category id; edits can clear fields; bon note payee fixed.

## Out of scope

Per-branch budgets and caps (E10), expense approvals outside the bon engine.
