# Branches v2 — one company, many towns

**Status: architecture agreed with the owner (2026-10-24); being built in phases (§12).**
Foundation shipped on branch `branches-v2` (`039ddffe`): headquarters branch row,
`branch_id` on vouchers / expenditures / cash accounts.

Builds on the v1 spec `.claude/skills/cncms-context/references/branches-and-locations.md`
(branches are rows inside a tenant's schema, never tenants). Informed by how Odoo,
ERPNext, NetSuite, Business Central, Zoho Books, Square and Workday model branches,
and by PostgreSQL scoping/indexing practice (sources at the end).

---

## 1. Decisions (owner, 2026-10-23 / 24)

| # | Decision |
|---|---|
| D1 | **One company = one tenant. A branch = a town** (Kumba, Buea, Limbe, Douala…). A town never has two branches, so there is no separate "town" level. Towns as tenants was rejected: it breaks staff transfers, one login, HQ consolidation and customer moves. |
| D2 | **Headquarters is a branch row** (`branches.is_headquarters`, exactly one, cannot be deleted). HQ costs, HQ staff and HQ accounts belong to it. A one-town company has only HQ and notices nothing. |
| D3 | **A branch is a closed world** for its staff: own manager, workers, customers, cash accounts. **No joint account** between branches. Only the owner, investors and HQ-level staff see across branches. |
| D4 | **Prices:** HQ sets the default list; a branch may override any price freely with a reason (in practice after consulting HQ). Same inherit/override rule for installation offers and fees. |
| D5 | **One company-wide billing calendar**, controlled by HQ (one manuscript run, branch prices applied). |
| D6 | **Money up:** both — a branch can send money to HQ (transfers), and HQ can always read every branch's figures. Each branch has a "sends to HQ" setting. |
| D7 | **Hiring:** HQ can hire into any branch; a branch manager hires into their own branch only. Workers can transfer; their past work stays with the old branch. |
| D8 | **Salaries:** HQ defines grades with min–max ranges; the branch sets each worker's salary inside the range; outside the range needs HQ approval. |
| D9 | **Performance first:** no deep join chains, no N+1. Every branch-owned row carries its own indexed `branch_id`. |

## 2. The governing rules

1. **The branch is stamped on a row when it is created, by the server, and never changes.**
   Reports always group by the row's own `branch_id` — never by looking up a
   customer's zone or a worker's current branch today. This is what keeps
   Kumba's October income Kumba's forever, even after a zone, customer or worker
   moves. (Every system researched does this; the ones that allowed "bulk update
   branch on transactions" rewrote history.) A wrong branch is fixed with a
   reversal/correction, not an edit.
2. **A fenced user can never choose a branch.** Writes derive it from the actor
   (their fence) or from the parent record (customer → its branch, bon → its
   branch). Request input naming a branch is ignored for fenced users.
3. **Consolidated = no branch filter.** HQ views are one `GROUP BY branch_id`
   query, never a loop over branches and never a sum of caps.
4. **Transfers between branches are never income or expense.** They are
   excluded from every P&L and net to zero in the consolidated view.
5. **Single-branch tenants see nothing new** — no switcher, no branch columns,
   no extra steps, while they have only HQ.

## 3. Who sees what

`tenant_users.branch_id` stays the single fence (v1):

| Person | `branch_id` | Sees / acts on |
|---|---|---|
| Owner (super), admins at HQ level, investors | `null` | every branch + consolidated; a **branch switcher** (All / HQ / Kumba / …) narrows any screen |
| Branch manager, cashier, workers of Kumba | Kumba | Kumba only |
| HQ staff (HQ cashier, HQ accountant) | HQ | HQ only (HQ's own costs, accounts, transfers received) |
| Agents | from their zone (v1, unchanged) | their branch; payments additionally their zone |

Investors are read-only consolidated (with per-branch breakdown).

## 4. Data model

### 4.1 Branches

`branches`: `id, uuid, name, is_headquarters` (built) — add `code` (short, e.g.
`KBA`, used in document numbers), `town`, `phone`, `address`, `manager_user_id`
(nullable), `sends_to_hq` (`off | weekly | monthly`), `status` (`active | closed`).
A branch with history is **closed**, never deleted.

### 4.2 Table classification (every tenant table)

**Branch-owned — gets its own `branch_id` (NOT NULL once backfilled), stamped at
creation, indexed:**

| Table | Stamped from |
|---|---|
| `customers` | its zone's branch (current owner — see 4.3) |
| `payments`, `manuscripts`, `customer_charges`, `customer_service`, `field_installations`, `free_month_grants`, `arrears_adjustments`, `opening_balance_entries`, `messages` | the customer's branch **at the time** |
| `payment_verifications`, `payment_receipts` | the payment |
| `complaints` | the customer (or zone) |
| `agents` | the zone (current) |
| `bill_batches`, `bill_batch_files` | the zone / batch scope |
| `vouchers` ✅, `voucher_approvals` | requester's fence (built) / the bon |
| `expenditures` ✅, `budgets` | the bon (built) / set per branch (P5) |
| `cash_accounts` ✅, `cash_movements`, `cash_day_closes` | holder / HQ (built) / the account |
| `remittances` | the collector's branch |
| `technician_fee_payouts` | the till it was paid from |
| `receipt_blocks`, `field_devices` | the holder's branch (a device's block: the device's) |
| `audit_logs`, `bill_batch_files` | the audited row / the zone — **nullable** (company-wide rows, all-zones files) |

**Built in P1** (all of the above, NOT NULL except the two nullable ones), with
`App\Models\Concerns\BelongsToBranch` stamping and `App\Support\BranchLookup`
keeping bulk writers free of per-row lookups.

**Child rows with no column** (always read through their parent, fenced by it):
`voucher_approvals`, `remittance_lines`, `receipt_leaves`,
`complaint_escalations`, `notification_recipients`.

**Later phases:** `budgets` (P5 — unique key becomes category+period+branch),
`notifications` (P6 — nullable source branch for alert routing). Company-wide:
`bill_batches`, `opening_balance_batches`, `sync_queue`. (`alerts` was dropped
in 2026-08; `user_activitylogs` is unused.)

**HQ settings with branch override (inherit unless overridden):** `companies`
(MoMo numbers, contacts, fees, receipt/bill details), `services.price`,
`service_variants.price`, `installation_offers.fee`.

**Company-wide:** `branches`, `roles`, `role_permissions`, `expense_categories`,
`scheduled_tasks`, `command_runs`, `document_counters`, `receipt_counters`,
`notification_settings`, push/token/media tables, `expenditure_months`
(company-wide period lock — §8).

### 4.3 Customers and zones

- A zone belongs to one branch (`zones.branch_id`, v1).
- `customers.branch_id` is the **current owning branch** and must equal its
  zone's branch. The database enforces it: `zones` gets `UNIQUE (id, branch_id)`
  and customers reference `FOREIGN KEY (zone_id, branch_id) REFERENCES zones (id, branch_id)`.
- Everything a customer generates (payments, bills, charges…) gets a **plain**
  `branch_id` FK to `branches` — not composite — because history must survive a move.
- **Moving a customer to another town** is a dated, audited event
  (`customer_branch_moves`: customer, from/to branch and zone, effective date,
  by, reason). Arrears/credit stay on the customer; old payments and bills stay
  with the old branch; billing from the effective period is the new branch's.
  Moving a whole zone = moving its customers (one event per customer, batched).

### 4.4 Staff

- `tenant_users.branch_id` = current branch (fast fence, unchanged).
- New `staff_assignments` (user, branch, role, `starts_on`, `ends_on`, by, reason).
  A transfer closes one row and opens the next — "where did X work on date D".
- Hiring: HQ-level users choose any branch; a branch manager's new staff are
  forced to their branch (rule 2). Transfers are HQ-only.

### 4.5 Salaries (D8)

- `salary_grades` (HQ): name (Agent, Cashier, Manager…), `min_amount`, `max_amount`.
- `staff_salaries` (effective-dated): user, branch, grade, amount, `effective_from`,
  `status` (`approved | pending_hq | rejected`), set_by, decided_by, reason.
  Inside the range → approved immediately. Outside → `pending_hq` (a warning,
  not a block — HQ approves or rejects; the previous salary stays in force meanwhile).
- `agents.salary` becomes the agent's current approved salary (kept for the
  mobile app), and non-agent staff get salaries for the first time.
- Paying salaries (a monthly payroll run producing bons from the branch's
  accounts) is a later step; the structure is ready for it.

### 4.6 Settings and prices (D4)

- **`branch_settings`** — one optional row per branch, every column nullable =
  "inherit from HQ": `momo_mtn`, `momo_orange`, `phone`, `email`, `address`,
  `relocation_fee`, `reinstallation_fee`, `reconnection_fine`, bill/receipt footer.
- **`branch_prices`** — sparse overrides: `branch_id`, exactly one of
  `service_id | service_variant_id | installation_offer_id` (real FKs + a CHECK
  that exactly one is set), `price`, `reason` (required), `set_by`,
  `effective_from`. Unique per (branch, target, effective_from). The previous
  rows are the price history.
- **Resolution** (one service, `App\Support\BranchSettings` / `BranchPrices`):
  `branch override ?? HQ value`. Loaded **once per branch per request/job** into
  keyed maps and cached per (tenant, branch); never one query per customer.
  `Company::cached()` stays the HQ record; per-branch values are read through
  `BranchSettings::for($branchId)`, and queue jobs pass the branch id
  explicitly (they have no request context).
- **Repricing:** a branch override reprices that branch's subscribers the same
  way an HQ price change reprices everyone today
  (`CustomerSubscriptionService`), and an HQ change no longer touches
  subscribers in branches that override that price. Bills already snapshot the
  amount (`customer_service.price` → manuscript), so history never moves.

### 4.7 Money (D3, D6)

- **Accounts:** each branch has its own tills, safe, MoMo wallet(s) and bank
  account(s) (`cash_accounts.branch_id`). A bon is paid only from an account of
  its own branch; handovers and day closes stay inside one branch.
- **Bons:** a branch manager approves own-branch bons within the limit; above
  the limit the stakeholders (investors, owner when none) approve, as today.
  Company limits (single bon, daily) and the monthly cap remain the hard
  ceiling; **per-branch budgets** sit underneath (warn first, block later).
- **Transfers** (`branch_transfers`): from branch/account → to branch/account,
  amount, status `sent → received | rejected`, sent_by/at, received_by/at, note.
  Sending writes the `transfer_out` movement; receiving writes `transfer_in`.
  While `sent`, the money is "in transit" — visible to both sides and to HQ,
  flagged if not received within N days. Transfers never count as income or
  expense (rule 4).

### 4.8 Billing calendar (D5)

One company-wide manuscript run and one period lock (as today), controlled by HQ.
Bills and receipts render with **their branch's** settings (name, MoMo, phone).
Optional later: a per-branch "month submitted" flag inside the company lock.

## 5. Enforcement — how fencing is made leak-proof

The audit found the leak risk is not one big hole but many small queries
(counts, pickers, exports, digests, sync status). Relying on every query being
remembered is what the v1 doc warned about, so v2 enforces it structurally:

1. **`BelongsToBranch` model trait** on every branch-owned model:
   - `creating`: stamps `branch_id` from the parent/actor if not set; refuses to
     save without one once the column is NOT NULL.
   - `updating`: refuses to change `branch_id` (immutability; moves go through
     their own services, which use an explicit bypass).
   - a **global scope** that applies **only when a fenced `TenantContext` is
     bound** (unfenced users, artisan commands and queue jobs → no-op). This
     reverses v1's "no global scope": with ~30 branch-owned tables and
     ~50 entry points, fail-closed beats remembered-every-time. Explicit
     `withoutBranchScope()` is allowed only in a few named system services.
   - `scopeForBranch(?int)` for the HQ switcher and reports.
2. **Route model binding** uses the same scope (replaces
   `ScopesRouteBindingToBranch`'s zone joins) — another branch's uuid is a 404.
3. **`ScopesByBranch` repository concern** becomes `where('branch_id', …)`;
   all `whereHas('zone' / 'customer.zone')` chains are removed.
4. **Architecture test**: every model with a `branch_id` column uses
   `BelongsToBranch`; every table in §4.2 has the column, index and FK.
5. **Fence tests per module**: "a Kumba manager sees and acts on Kumba only" for
   lists, detail pages, counts, exports, PDFs, alerts, digests and every sync
   endpoint.
6. Postgres row-level security is **not** used now (adds a second session
   variable across requests and jobs, against the "keep permissions simple"
   rule); it remains an optional later hardening layer on the same column.

## 6. Performance

- **Direct column instead of joins:** payment/manuscript queries drop their
  double `EXISTS` through customer → zone; reports drop their customer/zone joins.
- **Indexes (driven by real queries, kept few):** `(branch_id, period)` on
  manuscripts; `(branch_id, paid_at)` / `(branch_id, period)` on payments;
  `(branch_id, created_at)` on vouchers, expenditures (`spent_at`), cash
  movements; `(branch_id, status)` on customers. Existing period-only indexes
  stay for HQ-wide queries. Partial/`INCLUDE` indexes only where `EXPLAIN` asks.
- **HQ month-end:** live `GROUP BY branch_id` for the open month; a frozen
  `branch_period_totals` row per branch written when the month closes (audit
  value as much as speed). No materialized views, no partitioning at this size.
- **N+1 guard:** `Model::preventLazyLoading(! app()->isProduction())`; price/
  setting maps loaded once; `chunkById` in long jobs.
- **Known hotspots to fix (audit):** `ReportService.php:605` reads every central
  user (also a cross-tenant leak); `PaymentController.php:135-138` unfenced
  full-table tab counts; `SyncService.php:260-264` unfenced counts on every
  status call; `AuditLogService.php:238` per-row category lookup;
  `ManuscriptRepository` aggregates run the fenced query twice.

## 7. Backfill (per tenant, under maintenance mode)

For each table: add nullable `branch_id` → backfill from the parent in id-range
batches (customers from zone; payments etc. from customer; receipts from
payment) → index → `CHECK (branch_id IS NOT NULL) NOT VALID` → `VALIDATE` →
`SET NOT NULL`. Today every tenant has one branch, so every row resolves to HQ /
Main Branch; the composite customer → zone FK is added after the backfill.

## 8. UI

- **Branch switcher** (unfenced users, 2+ branches) in the top bar: All / HQ /
  each branch; remembered in the session; every list, dashboard and report
  honours it. Hidden for single-branch tenants and fenced users.
- **Branches page**: details, manager, settings overrides, price overrides
  (with reason, history), "sends to HQ" policy, close branch.
- **HQ dashboard**: branch comparison — collections, bills, collection rate,
  spending, net, cash on hand, money in transit, budget use.
- **Branch manager dashboard**: the same figures for their branch only.

## 9. Mobile

Sync is already zone/branch fenced for pull; v2 adds: branch settings (MoMo,
phone) in the pull payload for receipts, and fencing of the receipt upload
lookup and status counts (audit). Nothing from another branch is ever synced.

## 10. What stays company-wide on purpose

Roles and permissions, expense categories, document numbering (bon/receipt
numbers carry the branch `code` prefix instead of separate counters), the
billing period lock, and identity (one login per person, across transfers).

## 11. Further decisions (owner agreed, 2026-10-24)

| # | Decision |
|---|---|
| D10 | Bon limits (single bon, daily) are the **same company-wide numbers for every branch**, so a new branch never multiplies the allowance. |
| D11 | A customer who moves town mid-month: the **new branch bills from the next period**; the current month stays with the old branch. |
| D12 | A branch's alerts go to **that branch's manager + owner + investors** — never to another branch's manager. |
| D13 | **Fencing is enforced in the application** (§5), not with Postgres row-level security. RLS may be added later on the most sensitive tables (payments, cash movements, vouchers) as a second lock; the per-table `branch_id` keeps each policy a one-liner. |
| D14 | **Production never connects as a superuser** — the app uses its own `cncms` role (already so in `deploy/PLOI.md` and `deploy/scripts/provision.sh`). If RLS is added, the tables also need `FORCE ROW LEVEL SECURITY` (the `cncms` role owns them) and the branch must be set with `SET LOCAL` per transaction. |
| D15 | **Standing bar for every phase: no known vulnerability and no avoidable slow query ships.** Each phase ends with a security review of its diff (fencing, mass assignment, authorization, input validation) and an `EXPLAIN` check of its new or changed hot queries. |

## 12. Build phases (each ends green and shippable; nothing visible changes until P2)

| Phase | Scope |
|---|---|
| **P0 Hygiene** ✅ | `preventLazyLoading` (non-prod); fix the §6 hotspots and the central-users leak. |
| **P1 Data spine** ✅ | `branch_id` on every §4.2 table + backfill + indexes + NOT NULL; `BelongsToBranch` (stamping + immutability); customer→zone composite FK; replace every `whereHas` zone chain with the direct column. No behaviour change. |
| **P2 Fence** ✅ | Global branch scope for fenced users; route binding; money-out, cash book, alerts/digests, complaints, arrears, audit log, exports, sync fenced; branch switcher; fence tests per module. *After P2 it is safe to fence staff to a branch.* |
| **P3 Settings & prices** | `branch_settings`, `branch_prices`, resolvers, repricing, bills/receipts/messages per branch. Branch details on the Branches page. |
| **P4 Staff & salaries** | `staff_assignments`, transfers, branch-manager hiring, `salary_grades`, `staff_salaries` with HQ approval. |
| **P5 Money between branches** | Per-branch accounts on screens, `branch_transfers` (send/receive, in-transit flag), "sends to HQ" policy, branch budgets. Customer moves between branches. |
| **P6 HQ reporting** | Branch comparison dashboard, `branch_period_totals` at close, investor per-branch view, alert routing per branch. |

## Sources

Odoo companies & branches — odoo.com/documentation/18.0/applications/general/companies.html ·
ERPNext accounting dimensions — docs.frappe.io/erpnext/accounting-dimensions ·
ERPNext branch fund transfer — discuss.frappe.io/t/fund-transfer-between-branches-accounting-dimensions/68599 ·
Frappe HR employee transfer — docs.frappe.io/hr/employee-transfer ·
NetSuite classifications — docs.oracle.com (section_N261486) ·
Business Central responsibility centers & dimensions — learn.microsoft.com/dynamics365/business-central ·
Zoho Books branches & transaction lock — zoho.com/books/help/branches ·
Square per-location price overrides — squareup.com/help/us/en/article/8242 ·
Workday grades — doc.workday.com ·
Citus multi-tenant modelling — docs.citusdata.com/en/stable/use_cases/multi_tenant.html ·
PostgreSQL multicolumn indexes, RLS, partitioning — postgresql.org/docs/current ·
Laravel 13 lazy-loading prevention — laravel.com/docs/13.x/eloquent-relationships#preventing-lazy-loading
