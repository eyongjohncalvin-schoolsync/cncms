<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The fixed permission catalog for RBAC v2 (see
 * docs/plans/rbac-v2-configurable-roles.md, "Wave 1: final catalog").
 *
 * This is deliberately the FIRST string-backed enum in this codebase — the
 * rest of the app uses `const array` + `Rule::in()` (see
 * App\Models\Company::BILL_TEMPLATES). It's an enum here because the RBAC v2
 * design has one hard requirement the const-array pattern can't express as
 * cleanly: the roles-&-permissions matrix UI (Wave 3) may only ever *toggle*
 * these values, never invent new ones — the enum's closed set of cases IS
 * that guard. `role_permissions.permission` rows are validated against
 * Permission::values() before insert, and App\Providers\PermissionServiceProvider
 * defines one Laravel Gate ability per case.
 *
 * Each case maps to one or more current Policy methods / hardcoded role
 * checks — the full mapping (and the exact role set each currently grants,
 * which the default seed reproduces so day-1 behaviour is identical) is the
 * table in the plan doc. Grouping below mirrors that table's areas.
 *
 * NOT in this catalog, on purpose (they are orthogonal scope fences or
 * per-user tier flags, not role abilities — see the plan doc's "Scope
 * fences ... are orthogonal and unchanged"):
 *   - the `agent` zone fence on PaymentPolicy::verify() / CustomerPolicy::disconnect()
 *   - PaymentPolicy's `tenant_users.can_record_payments` worker grant
 *   - ReportPolicy::view()'s `tenant_users.is_investor` grant
 * Wave 2 keeps each of those as an additive OR-branch next to the `can()` call.
 */
enum Permission: string
{
    // ---- Customers -----------------------------------------------------
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersArchive = 'customers.archive';
    case CustomersPrintBill = 'customers.print_bill';
    /** disconnect / suspend / reconnect, single + bulk (agent zone-scoped disconnect stays an OR-branch). */
    case CustomersChangeStatus = 'customers.change_status';
    /** The /disconnections status workboard (CustomerPolicy::viewStatusBoard). */
    case CustomersStatusBoard = 'customers.status_board';
    /** The arrears "flagged for non-payment" list — broader than the status board: agents see their own zone (CustomerPolicy::viewEligibilityBoard). */
    case CustomersEligibilityBoard = 'customers.eligibility_board';
    /**
     * The "Export full record" action on Customers/Show — a single
     * downloadable PDF / multi-sheet XLSX bundling EVERYTHING CNCMS holds
     * about one customer (profile, every payment + verification + receipt,
     * every manuscript, arrears adjustments, messages, complaints, the full
     * audit trail), for an auditor or a billing dispute. See
     * App\Services\CustomerRecordExportService and
     * docs/plans/customer-record-export.md. It is a complete, UNREDACTED
     * data dump, so DefaultRolesSeeder seeds it to super + admin only — it
     * is deliberately NOT in the manager / agent / worker sets.
     */
    case CustomersExportRecord = 'customers.export_record';
    /**
     * Typing a service price of one's own on a customer, instead of the
     * service's default or one of its price options (owner, 2026-10-27).
     * Never below the service's minimum price, whoever types it
     * (CustomerSubscriptionService::assertPricesAllowed()). Seeded to
     * admin + manager.
     */
    case CustomersCustomPrice = 'customers.custom_price';

    // ---- Payments -----------------------------------------------------
    case PaymentsView = 'payments.view';
    /** create + bulkCreate + attachReceipt (worker+can_record_payments stays an OR-branch). */
    case PaymentsCreate = 'payments.create';
    case PaymentsUpdate = 'payments.update';
    case PaymentsDelete = 'payments.delete';
    /** verify + bulkVerify class gate (agent zone fence stays an OR-branch + per-item recheck). */
    case PaymentsVerify = 'payments.verify';
    /**
     * The manual "Issue / re-issue receipt" action on the payment detail
     * page (Wave 2 of docs/plans/payment-receipts-and-whatsapp.md). Auto-
     * issue on verify() needs no permission — it is a side effect of an
     * already-authorised approval — but issuing/re-issuing a receipt by hand
     * (for a payment recorded before receipts shipped, or a correction) is
     * gated. Seeded to the same roles that hold payments.verify.
     */
    case PaymentsIssueReceipt = 'payments.issue_receipt';
    /**
     * Prepayment offers ("4 months for 10,000"): set headquarters' list in
     * Settings. Collectors only pick an offer when recording a payment
     * (payments.create); a branch's own price for one is branches.settings.
     */
    case PaymentsOffers = 'payments.offers';

    // ---- Manuscripts -------------------------------------------------
    case ManuscriptsView = 'manuscripts.view';
    case ManuscriptsExport = 'manuscripts.export';
    case ManuscriptsCalculate = 'manuscripts.calculate';
    case ManuscriptsSendBill = 'manuscripts.send_bill';

    // ---- Zones ------------------------------------------------------
    case ZonesView = 'zones.view';
    /** create / update / delete / import — one role set today. */
    case ZonesManage = 'zones.manage';

    // ---- Field agents ---------------------------------------------
    case AgentsView = 'agents.view';
    case AgentsManage = 'agents.manage';

    // ---- Branches -------------------------------------------------
    case BranchesView = 'branches.view';
    case BranchesManage = 'branches.manage';
    /** Set one's OWN branch's contact details, MoMo number, fees and prices (branches v2 D4). */
    case BranchesSettings = 'branches.settings';
    /**
     * Salaries (branches v2 D8): set staff salaries (a fenced manager: their
     * branch's staff); unfenced holders also keep the salary grades and
     * approve salaries outside a grade's range.
     */
    case SalariesManage = 'salaries.manage';

    // ---- Expenditures -------------------------------------------
    case ExpendituresView = 'expenditures.view';
    /**
     * Retired (docs/plans/expenditures-and-spending-controls.md, E1): every
     * cash-out goes through a bon, so nothing records an expenditure
     * directly any more. Kept in the catalog so existing role rows stay
     * valid; it grants nothing.
     */
    case ExpendituresCreate = 'expenditures.create';
    /** Re-categorise / fix the description of an expenditure, with a reason (E7). */
    case ExpendituresUpdate = 'expenditures.update';
    /** Void a legacy expenditure or correct a paid bon, with a reason (E7). Never a hard delete. */
    case ExpendituresDelete = 'expenditures.delete';
    /** The P&L dashboard (ExpenditurePolicy::viewDashboard / ResourcesDashboardController). */
    case ExpendituresDashboard = 'expenditures.dashboard';
    /** Expense category create / update / deactivate (ExpenseCategoryPolicy). */
    case ExpenseCategoriesManage = 'expense_categories.manage';
    /**
     * Per-category monthly budgets — Resources → Budgets (E5). Warnings
     * only; they never block a bon.
     */
    case BudgetsManage = 'budgets.manage';

    // ---- Reports -------------------------------------------------
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // ---- Complaints --------------------------------------------
    case ComplaintsView = 'complaints.view';
    case ComplaintsCreate = 'complaints.create';
    /** resolve / reopen / linkDuplicate (the "not the submitter" identity check stays in the policy). */
    case ComplaintsResolve = 'complaints.resolve';
    case ComplaintsAssign = 'complaints.assign';
    case ComplaintsNotifyInvestors = 'complaints.notify_investors';

    // ---- Arrears adjustments ---------------------------------
    case ArrearsView = 'arrears.view';
    case ArrearsRequest = 'arrears.request';
    /** approve + reject (the stage machine + maker≠checker identity rules stay in the policy). */
    case ArrearsApprove = 'arrears.approve';

    // ---- Installations (docs/plans/installation-and-promotions.md) ----
    /**
     * Open an installation charge on a customer (the fee they owe outside
     * the monthly bill) and ask for what is left of it to be waived.
     * Recording an instalment against a charge is `payments.create`.
     */
    case InstallationsManage = 'installations.manage';
    /**
     * Approve / reject an installation waiver. Maker ≠ checker: whoever
     * asked for the waiver can never approve it (the owner excepted, as for
     * arrears adjustments). Seeded to admin only (D8: admin/owner approval).
     */
    case InstallationsApprove = 'installations.approve';
    /**
     * Settings → Installation offers: the company's standard installation
     * deals (fee + free months per service). Managers only pick an offer
     * (installations.manage); setting them is admin/owner (D8).
     */
    case InstallationsOffers = 'installations.offers';
    /**
     * Register a customer in the field, offline, with the installation (and
     * months paid upfront) money and field receipts (phase 3, D10). Only in
     * the agent's own zone; the customer waits as "pending activation"
     * until the office activates it. Seeded to agent.
     */
    case InstallationsField = 'installations.field';
    /**
     * Activate or reject a field registration ("Pending activation"):
     * confirm or correct zone, free months and connection date, or reject
     * it (payments rejected, receipts voided, refund bon raised, customer
     * archived). Seeded to manager and admin.
     */
    case InstallationsActivate = 'installations.activate';

    // ---- Opening balances (onboarding) -----------------------
    /** See the opening-balances screen and its batches. */
    case OpeningBalancesView = 'opening_balances.view';
    /** Enter / edit a draft of starting balances and submit it for approval. */
    case OpeningBalancesEnter = 'opening_balances.enter';
    /**
     * Approve / reject a submitted batch, or reopen an approved one before
     * the first bill run locks it. Seeded to no role but the owner (super
     * bypasses) — assign it to extra hands via Roles & permissions.
     */
    case OpeningBalancesApprove = 'opening_balances.approve';

    // ---- Receipt stock (docs/plans/cash-controls.md) ---------
    /** Allocate / print receipt books, cancel unused numbers, see who holds which numbers. */
    case ReceiptsManageStock = 'receipts.manage_stock';

    // ---- Cash book (docs/plans/cash-controls.md) -------------
    /** The cash book, every remittance and the cash alerts. */
    case CashView = 'cash.view';
    /** Submit one's own end-of-day remittance (the daily income form). */
    case CashRemit = 'cash.remit';
    /** Receive remittances into a till, record deposits / transfers, close a till's day. */
    case CashReceive = 'cash.receive';

    // ---- Vouchers / bons de caisse ---------------------------
    /** Request a voucher or a cash advance. */
    case VouchersRequest = 'vouchers.request';
    /**
     * Approve / reject vouchers within the company's limits. Above a limit
     * every investor must approve instead — that is the `is_investor` flag,
     * not this permission (VoucherPolicy).
     */
    case VouchersApprove = 'vouchers.approve';
    /** Pay out an approved voucher and settle cash advances. */
    case VouchersPay = 'vouchers.pay';

    // ---- Audit -------------------------------------------------
    case AuditView = 'audit.view';

    // ---- Company / settings ---------------------------------
    /** Company info + notification settings + bill-printing settings (all "view" today = everyone). */
    case CompanyView = 'company.view';
    /** Company info + notification settings update (CompanyPolicy::update / NotificationSettingPolicy::update). */
    case CompanyUpdate = 'company.update';
    /**
     * The Settings -> Services catalogue (create / edit / activate /
     * deactivate / delete a service, "apply price to all subscribers") —
     * App\Policies\ServicePolicy. Seeded to `admin` only (super bypasses);
     * `manager` is NOT granted it — catalogue pricing is an admin decision
     * (services.md section 6). Viewing the tick list on the customer form
     * needs no permission (any user who can create a customer sees it).
     */
    case ServicesManage = 'services.manage';

    // ---- Command runs / task scheduler --------------------
    case CommandRunsView = 'command_runs.view';
    /** publish + cancel + rollback + unpublish a run (CommandRunPolicy::publish). */
    case CommandRunsPublish = 'command_runs.publish';
    /** edit a scheduled task's enabled/schedule_config (CommandRunPolicy::manageSchedule). */
    case CommandRunsSchedule = 'command_runs.schedule';

    // ---- Users & roles ------------------------------------
    case UsersView = 'users.view';
    /** add / edit users, assign roles, deactivate (TenantUserPolicy). */
    case UsersManage = 'users.manage';
    /** edit the role→permission matrix, add/remove custom roles (Wave 3 UI; no policy yet). */
    case RolesManage = 'roles.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /**
     * Grouped for the Wave 3 matrix UI — area label => the permissions in
     * that area, in declaration order. Keys double as the section headings
     * the checkbox grid renders.
     *
     * @return array<string, list<self>>
     */
    public static function byArea(): array
    {
        $areas = [];

        foreach (self::cases() as $permission) {
            $areas[$permission->area()][] = $permission;
        }

        return $areas;
    }

    /**
     * The area label this permission belongs to — derived from the string
     * prefix so a new case never needs a second edit here to be grouped.
     */
    public function area(): string
    {
        return match (explode('.', $this->value)[0]) {
            'customers' => 'Customers',
            'payments' => 'Payments',
            'manuscripts' => 'Manuscripts',
            'zones' => 'Zones',
            'agents' => 'Field agents',
            'branches' => 'Branches',
            'expenditures' => 'Expenditures',
            'expense_categories', 'budgets' => 'Expenditures',
            'reports' => 'Reports',
            'complaints' => 'Complaints',
            'arrears' => 'Arrears adjustments',
            'installations' => 'Installations',
            'opening_balances' => 'Opening balances',
            'receipts', 'cash' => 'Cash & receipts',
            'vouchers' => 'Vouchers',
            'audit' => 'Audit',
            'company', 'services' => 'Company & settings',
            'command_runs' => 'Task scheduler',
            'users', 'roles' => 'Users & roles',
            default => 'Other',
        };
    }
}
