# Branches v2 — full separation per branch (design for review)

**Status: for review and design — not scheduled.** Owner, 2026-10-23: the
branch feature is broad and needs careful planning; treat it as **version 2**
of the app. Only the **foundation** (below) may be built before the design is
agreed.

Builds on the v1 spec: `.claude/skills/cncms-context/references/branches-and-locations.md`
(branches are rows inside a tenant's schema, never separate tenants; staff are
"null = all branches, or exactly one branch"; agents inherit a branch from their
zone; enforcement is repository scoping via a shared concern, not a global scope).

## 1. The governing principle — separation of concerns

> **Once a business has more than one branch, a branch is a closed world.** A
> branch manager and that branch's field agents see and act on **their branch
> only** — customers, payments, money out, cash, staff, reports, alerts. They
> are never shown another branch's data, totals, names, or even that it exists
> beyond what they need. Only the **owner (super)** and **investors** see across
> branches, through **consolidated** views.

Most open questions disappear under this rule: a branch manager can only see,
raise and approve their own branch's bons; their dashboard compares their
branch's income with their branch's spending; their agents can only ever be
assigned to their branch's zones; reports, exports, alerts and notifications are
filtered the same way.

**Single-branch businesses must notice nothing.** Everything below stays
invisible (no switcher, no branch columns, no extra steps) while a tenant has one
branch.

## 2. Where things stand today (2026-10-23)

**Branch-aware (v1, built):** `branches` table (every tenant has "Main Branch");
`zones.branch_id` (NOT NULL); customers / payments / manuscripts / agents get
their branch through their zone; `tenant_users.branch_id` (null = all branches);
`App\Repositories\Concerns\ScopesByBranch` fences customers, payments,
manuscripts, zones, agents and reports; agents are fenced through their zone;
the Users Control Center shows a branch picker when there are 2+ branches;
`companies.branch_id` exists but `Company::cached()` still reads `first()`.

**Not branch-aware (gaps — every one of these is a leak once a user is fenced):**

| Area | Today | Leak once fenced |
|---|---|---|
| Bons (vouchers) | no branch column; policy and approval ignore branch | a branch manager sees and approves every branch's bons |
| Expenditures | no branch column | every branch's spending visible; branch P&L impossible |
| Budgets / monthly cap / daily limit | tenant-wide | a branch manager (has `budgets.manage`) edits company budgets |
| Resources dashboard / deficit | income fenced, expenses not | branch income vs company expenses → false "deficit" |
| Cash book (tills, wallets, handovers, day close) | per user, no branch | a Kumba cashier can pay a Buea bon; branch cash can't be reconciled |
| Installation offers, technician-fee payouts, receipt stock (books), alerts, digests, send queue, complaints, audit log, landlord activity | tenant-wide (mostly) | need an explicit scoping decision each |
| Company settings (name, MoMo, logo, limits) | one row read via `first()` | per-branch MoMo / logo / contacts not possible |

None of these has hurt anyone yet because **no staff are fenced** and every
tenant runs one branch.

## 3. Business logic that must be decided (the real design work)

Grouped so the owner can answer them area by area. Nothing is built until these
are settled.

**3.1 Structure**
1. Who creates a branch, and what is required (name, town, manager, MoMo number,
   receipt book prefix)? Can a branch be closed/merged, and what happens to its
   zones, customers, staff and money?
2. Can a customer or zone move between branches? (History stays with the
   customer; money already collected stays with the old branch?)

**3.2 People**
3. Roles per branch: branch manager, branch cashier, agents — same roles,
   fenced? Can one person serve two branches (v1 says no: one branch or all)?
4. Who creates staff for a branch: the owner only, or the branch manager for
   their own branch?

**3.3 Money in**
5. Per-branch MoMo numbers / bill and receipt details (company row per branch)?
6. Monthly manuscript run: one company-wide run (v1) — confirm. Installation
   offers and prices per branch, or company-wide?

**3.4 Money out (bons, expenses, budgets)**
7. Is a branch a **reporting label** or also a **cash boundary** (each branch
   has its own tills; a branch cashier pays only that branch's bons)?
8. Head office: which costs belong to no branch (rent, satellite/licence fees,
   owner and HQ salaries)? Who raises and approves head-office bons?
9. Approvals: branch manager approves own-branch bons under the limit;
   over-limit / over-cap still goes to every investor (owner when none)?
10. Caps and budgets: company cap stays the hard ceiling; per-branch caps and
    budgets underneath (warn first, block later)? The 50k bon and 100k daily
    limits stay company-wide so new branches don't multiply allowances?
11. Can a bon's branch be changed after approval/payment? By whom, with which
    re-checks?

**3.5 Cash controls**
12. Handover and day close per branch; each branch's cashier receives only that
    branch's agents? Transfers between branches (branch → head office banking)?
13. Receipt books issued per branch?

**3.6 Visibility and reporting**
14. Investors: consolidated only, or per-branch figures too?
15. Owner dashboard: branch comparison (income, spend, net, collection rate,
    cap use) — which figures?
16. Alerts and digests: who receives a branch's alerts (branch manager +
    owner + investors?) — never another branch's manager.

**3.7 Mobile**
17. Agents' offline data (customers, zones, offers) is already zone/branch
    scoped — confirm nothing from other branches is ever synced to a phone.

## 4. Architecture direction (to refine during design)

- **One scope resolver** (`TenantContext`) answers "which branch(es) may this
  user touch?" and every query goes through a shared concern — the v1
  `ScopesByBranch` pattern, extended. No per-feature hand-rolled filtering.
- **Every table is classified** as: branch-owned (direct `branch_id`),
  branch-derived (via zone/customer), head-office (null branch, owner/investors
  only), or tenant-wide settings. The classification is written down per table
  and enforced by tests.
- **Writes derive the branch on the server** from the actor (fence) or the
  record (customer's zone); a fenced user's request can never choose another
  branch.
- **Consolidated views are sums of branch rows plus head office** — never cap +
  caps, never double counted.
- **Security boundary = test boundary:** every module gets "fenced user sees /
  acts only on own branch" tests (v1 §7 already warns this is the larger task).

## 5. Foundation that can be built now (no behaviour change)

Safe, cheap groundwork so v2 has real history to work with — data that cannot be
reconstructed later:

- **Record the branch on money-out:** nullable `branch_id` on `vouchers` and
  `expenditures` (expenditure copies its bon's branch). Set automatically on the
  server: the requester's fence; for agents, their zone's branch; unfenced
  staff → the tenant's only branch while there is one (null = head office once
  there are several). Existing rows backfilled to "Main Branch" while each tenant
  has a single branch. **No new screens, rules or blocking.**
- Optionally the same tag on `cash_accounts` (from the holder's fence), so a
  future cash-boundary decision has data.

Everything else in §2's table waits for the design in §3, because the right fix
depends on those answers (and today no user is fenced, so nothing leaks yet).
**Before anyone is fenced to a branch, at least the money-out leaks must be
closed** — note this in the Users Control Center branch picker help text.

## 6. Proposed v2 phases (after §3 is answered)

1. Close the money-out leaks: fence bons, expenditures, budgets; no cross-branch
   approvals; consistent branch P&L.
2. Per-branch company details (MoMo, logo, contacts) and branch-scoped
   receipts/bills.
3. Branch reporting: owner comparison, investor views, alerts per branch.
4. Per-branch budgets (warn), then caps (block) if needed.
5. Branch cash boundary (tills, handovers, day close, inter-branch transfers),
   if decided in 3.5.
