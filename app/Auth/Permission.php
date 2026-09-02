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

    // ---- Expenditures -------------------------------------------
    case ExpendituresView = 'expenditures.view';
    case ExpendituresCreate = 'expenditures.create';
    case ExpendituresUpdate = 'expenditures.update';
    case ExpendituresDelete = 'expenditures.delete';
    /** The P&L dashboard (ExpenditurePolicy::viewDashboard / ResourcesDashboardController). */
    case ExpendituresDashboard = 'expenditures.dashboard';
    /** Expense category create / update / deactivate (ExpenseCategoryPolicy). */
    case ExpenseCategoriesManage = 'expense_categories.manage';

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

    // ---- Audit -------------------------------------------------
    case AuditView = 'audit.view';

    // ---- Company / settings ---------------------------------
    /** Company info + notification settings + bill-printing settings (all "view" today = everyone). */
    case CompanyView = 'company.view';
    /** Company info + notification settings update (CompanyPolicy::update / NotificationSettingPolicy::update). */
    case CompanyUpdate = 'company.update';

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
            'expense_categories' => 'Expenditures',
            'reports' => 'Reports',
            'complaints' => 'Complaints',
            'arrears' => 'Arrears adjustments',
            'audit' => 'Audit',
            'company' => 'Company & settings',
            'command_runs' => 'Task scheduler',
            'users', 'roles' => 'Users & roles',
            default => 'Other',
        };
    }
}
