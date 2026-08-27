export type Role = 'super' | 'admin' | 'manager' | 'agent' | 'worker';

export interface AuthUser {
    uuid: string;
    name: string;
    username: string;
    email: string;
    role: Role | null;
    /** Platform-wide flag, independent of role/tenant — see EnsureLandlord. */
    is_landlord: boolean;
    /**
     * Tenant-scoped Investor tier flag (tenant_users.is_investor) — see
     * App\Policies\ReportPolicy::view()'s docblock. Display hint only:
     * Reports/Index.tsx reads this to select InvestorLayout instead of
     * AppLayout; the server-side gate is ReportPolicy, not this flag.
     */
    is_investor: boolean;
}

export interface ImportFailedRow {
    row: number;
    reason: string;
}

/**
 * Row-level report from a bulk zone/customer import
 * (App\Services\ZoneImportService / CustomerImportService), flashed by
 * ZoneController::import()/CustomerController::import() alongside the
 * plain success/error string below.
 */
export interface ImportReport {
    type: 'zones' | 'customers';
    succeeded_count: number;
    failed_count: number;
    failed: ImportFailedRow[];
}

export type NotificationSeverity = 'info' | 'warning' | 'urgent' | 'emergency';

/**
 * One in-app notification, shaped by App\Http\Resources\NotificationResource
 * — see .claude/skills/cncms-context/references/in-app-notifications.md.
 * `read_at`/`acknowledged_at` are the CURRENT user's own state for this
 * notification, not global state (in-app-notifications.md section 5):
 * read is set passively when opened in the bell dropdown, acknowledged is
 * only ever set by an explicit "Acknowledge" action and never implied by
 * read.
 */
export interface AppNotification {
    uuid: string;
    type: string;
    severity: NotificationSeverity;
    title: string;
    body: string;
    link: string | null;
    source_type: string | null;
    source_uuid: string | null;
    created_at: string;
    read_at: string | null;
    acknowledged_at: string | null;
}

/**
 * Shared `notifications` prop (App\Http\Middleware\HandleInertiaRequests,
 * App\Services\NotificationService::feedForUser()) — null when there's no
 * authenticated tenant-scoped user, matching `auth.user`. Refreshed every
 * poll tick by AppLayout's usePoll(20000, { only: ['notifications'] }).
 */
export interface NotificationsFeed {
    items: AppNotification[];
    unread_count: number;
    /** Unacknowledged severity: 'emergency' notifications — drives the persistent banner. */
    emergency: AppNotification[];
}

export interface PageProps {
    auth: {
        user: AuthUser | null;
    };
    /**
     * Resolved locale for this request ('en' | 'fr'), set by
     * App\Http\Middleware\ResolveLocale and shared by
     * HandleInertiaRequests::share(). Fed into i18next at bootstrap (see
     * resources/tsx/app.tsx) — components read the active language via
     * useTranslation() instead of this prop directly.
     */
    locale: string;
    flash: {
        success?: string | null;
        error?: string | null;
        import?: ImportReport | null;
    };
    notifications: NotificationsFeed | null;
    [key: string]: unknown;
}

/**
 * One crumb in AppLayout's `breadcrumbs` trail (e.g. "Home / Customers /
 * John Doe"). All but the last item are rendered as links — omit `href` on
 * the final/current-page item so it renders as plain (non-link) text.
 * Contract is fixed across all pages; see components/ui/Breadcrumb.tsx.
 */
export interface BreadcrumbItem {
    label: string;
    href?: string;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedResponse<T> {
    data: T[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

export type CustomerStatus = 'active' | 'passive' | 'disconnected' | 'suspended';
export type CustomerLevel = 'normal' | 'Vip' | 'Operator';
export type SuspendReason = 'tv_problem' | 'poor_service' | 'customer_request' | 'zone_transfer' | 'other';

export interface Zone {
    uuid: string;
    name: string;
    town: string;
    customer_count?: number;
    /** Active agents currently assigned to this zone. Populated on Agents/Index for the "Change Zone" quick action. */
    agent_count?: number;
    /** Names of the active agents counted in agent_count, in the same order. */
    agent_names?: string[];
    branch_uuid?: string | null;
    branch_name?: string | null;
}

export interface Branch {
    uuid: string;
    name: string;
    zone_count?: number;
}

export interface Customer {
    uuid: string;
    name: string;
    phone: string | null;
    zone_uuid: string;
    zone_name: string;
    bill: string;
    others: string;
    level: CustomerLevel;
    status: CustomerStatus;
    status_reason: string | null;
    status_note: string | null;
    location: string | null;
    /**
     * When the customer's `status` was last changed — see
     * App\Services\CustomerStatusService, the only writer of this column.
     * Null for a customer that has never gone through a disconnect/suspend/
     * reconnect transition since prepaid-pause-handling.md shipped. Present
     * only where CustomerController::shapeCustomer() supplies it
     * (Customers/Index.tsx, Customers/Show.tsx) — the Disconnections board
     * and eligibility list don't need it and omit it, same convention as
     * `total_arrears` below.
     */
    status_changed_at?: string | null;
    /**
     * Set (true/false) only when this customer was suspended WITH an
     * active/unexpired prepaid window at the time — the admin's pause/
     * continue choice for the CURRENT suspension cycle
     * (references/prepaid-pause-handling.md). Null otherwise, including
     * always once reconnected (cleared back to null every time). Same
     * availability caveat as `status_changed_at` above.
     */
    prepaid_paused?: boolean | null;
    /**
     * Present only on rows returned by the Disconnections page's
     * "flagged for non-payment" tab (?eligible=1) — see
     * App\Services\CustomerEligibilityService::shape(). Absent on the
     * plain status-board tab.
     */
    total_arrears?: string;
    arrears_ratio?: number;
    months_overdue?: number;
    /**
     * Customer archiving (customer-deletion deliberation, 2026-08-29).
     * `has_billing_history` decides "Archive customer" vs "Delete row" in
     * the list action menu — present on CustomerController::shapeCustomer()
     * rows (Customers/Index + Show). The archived_* fields are non-null
     * only for a currently-archived customer (the ?archived=1 view and the
     * archived detail page).
     */
    has_billing_history?: boolean;
    archived_at?: string | null;
    archived_by_name?: string | null;
    archived_reason?: string | null;
}

export type VerificationStatus = 'pending' | 'verified' | 'rejected';
export type PaymentFrequency = 'monthly' | 'yearly' | 'months';

export interface PaymentVerification {
    uuid: string;
    status: 'pending' | 'approved' | 'rejected';
    receipt_photo_url: string | null;
    momo_ref: string | null;
    momo_status: string | null;
    verified_by: string | null;
    verified_at: string | null;
    notes: string | null;
}

export interface Payment {
    uuid: string;
    customer_uuid: string;
    customer_name: string;
    customer_bill: string;
    zone_name?: string | null;
    /**
     * The customer's current `total_arrears`, from `customer.latestManuscript`
     * — only populated on Payments/Show.tsx (App\Http\Controllers\
     * PaymentController::show() eager-loads that relation specifically for
     * this); always null on Payments/Index.tsx's list rows. Feeds the
     * "Adjust Arrears" entry point on the detail page — see
     * .claude/skills/cncms-context/references/arrears-adjustment.md's
     * 2026-08-27 addendum.
     */
    customer_total_arrears?: string | null;
    amount: string;
    credit: string;
    frequency: PaymentFrequency;
    months: number | null;
    expiration_date: string | null;
    verification_status: VerificationStatus;
    recorded_offline: boolean;
    recorded_by_device?: string | null;
    created_at: string;
    // The field agent's actual offline-collection timestamp, distinct from
    // `created_at` (server-arrival time) — see App\Services\SyncService::
    // pushPayment()'s doc comment. Null for a web-recorded payment or a
    // synced payment whose client didn't send a timestamp.
    collected_at: string | null;
    processed_at: string | null;
    verification?: PaymentVerification | null;
}

export interface Manuscript {
    customer_uuid: string;
    customer_name: string;
    customer_code: string;
    phone: string | null;
    zone_name: string | null;
    level: CustomerLevel;
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    /** Draw-down (references/prepayment-drawdown.md): whole billing periods
     * still covered by a months/yearly prepayment, and the rate locked when
     * it was paid. 0 / null outside a prepaid window. */
    prepaid_months_remaining: number;
    prepaid_rate: string | null;
    period: string;
    status: CustomerStatus;
    /**
     * wa.me deep link pre-filled with this customer's bill reminder
     * (App\Services\BillNotificationService::waLink()), or null when they
     * have no usable phone number on file, no manuscript to remind them
     * about, or are not an active customer (bills are only sent to active
     * customers) — see Manuscripts/Index.tsx's "Send Bill" action.
     */
    wa_link: string | null;
}

export interface ManuscriptSummary {
    total_customers: number;
    total_bill: string;
    total_arrears: string;
    total_credit: string;
    total_collected: string;
    collection_rate: number;
}

/**
 * One flagged row from GET /manuscripts/pre-run-review
 * (App\Services\ManuscriptPreRunReviewService::reviewList()'s `customers`
 * shape) — an active customer with nothing covering the upcoming period yet.
 * Shared by the pre-run review modal/panel (Manuscripts/Index.tsx's Calculate
 * confirm modal) and its large-count full-board companion
 * (Manuscripts/PreRunReviewList.tsx).
 */
export interface PreRunReviewCustomer {
    uuid: string;
    name: string;
    zone_uuid: string | null;
    zone_name: string | null;
    phone: string | null;
    bill: string;
    last_payment_date: string | null;
}

export interface PreRunReviewSummary {
    count: number;
    total_exposure: string;
}

/** GET /manuscripts/pre-run-review's full JSON response shape. */
export interface PreRunReviewResponse {
    period: string;
    summary: PreRunReviewSummary;
    customers: PreRunReviewCustomer[];
}

export interface CustomerManuscriptSummary {
    uuid: string;
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    prepaid_months_remaining: number;
    prepaid_rate: string | null;
    period: string;
}

export interface CustomerRecentPayment {
    uuid: string;
    amount: string;
    credit: string;
    frequency: PaymentFrequency;
    verification_status: VerificationStatus;
    created_at: string;
}

export type ArrearsAdjustmentDirection = 'decrease' | 'increase';
/** Which side of `net = arrears - credit` a correction lands on. 'credit'
 * touches only the loose manuscripts.credit figure — never prepaid coverage
 * (prepaid_months_remaining / prepaid_rate), which is out of scope. */
export type ArrearsAdjustmentTarget = 'arrears' | 'credit';
export type ArrearsAdjustmentReasonCategory =
    | 'legacy_migration_error'
    | 'billing_error'
    | 'goodwill_service_outage'
    | 'bad_debt_writeoff'
    | 'credit_clawback'
    | 'other'
    | 'credit_correction'
    | 'duplicate_credit'
    | 'migration_credit_error';
export type ArrearsAdjustmentStatus = 'pending' | 'pending_second_approval' | 'approved' | 'rejected';

/**
 * A maker-checker ledger correction against one customer's arrears balance
 * for one billing period — see App\Models\ArrearsAdjustment's class doc.
 * `amount` is always positive; `direction` carries the sign.
 */
export interface ArrearsAdjustment {
    uuid: string;
    target_period: string;
    direction: ArrearsAdjustmentDirection;
    /** 'arrears' (default) or 'credit'. Absent on rows created before the
     * 2026-08-30 credit-correction addendum — treat a missing value as
     * 'arrears'. */
    target: ArrearsAdjustmentTarget;
    amount: string;
    reason_category: ArrearsAdjustmentReasonCategory;
    reason_note: string;
    status: ArrearsAdjustmentStatus;
    requested_by_name: string | null;
    approved_by_name: string | null;
    second_approved_by_name: string | null;
    rejection_reason: string | null;
    created_at: string | null;
}

/**
 * The Audit Log page's "Arrears Adjustments" sub-tab row shape —
 * App\Http\Controllers\AuditLogController::arrearsAdjustmentsTabData().
 * `can_approve`/`can_reject` are server-resolved per row (from
 * App\Policies\ArrearsAdjustmentPolicy, which is state-dependent on the
 * adjustment's current status) — the frontend never re-derives these.
 */
export interface ArrearsAdjustmentAuditRow extends ArrearsAdjustment {
    customer_uuid: string | null;
    customer_name: string | null;
    /** The customer's arrears balance for target_period at request time —
     * the "before" figure. See ArrearsAdjustmentModal's identical field. */
    arrears_snapshot: string;
    /** The credit-side counterpart of arrears_snapshot — the "before" figure
     * for a `target === 'credit'` row. Null for arrears-target rows and for
     * rows created before the 2026-08-30 addendum. */
    credit_snapshot: string | null;
    can_approve: boolean;
    can_reject: boolean;
    /** True when the signed-in user raised this request. A `super` may still
     * approve/reject it (the maker≠checker carve-out in
     * App\Policies\ArrearsAdjustmentPolicy); the review UI shows a
     * confirmation step first so the bypass is explicit. */
    is_own_request: boolean;
}

export interface CustomerDetail extends Customer {
    description: string | null;
    created_at: string | null;
    manuscript: CustomerManuscriptSummary | null;
    recent_payments: CustomerRecentPayment[];
    arrears_adjustments: ArrearsAdjustment[];
}

export interface Company {
    uuid: string;
    name: string;
    location: string;
    /** Full formal head-office postal address, distinct from `location` (a short area/town tag). */
    head_office: string | null;
    email: string | null;
    phone: string;
    tech_number: string | null;
    momo_number: string | null;
    momo_name: string | null;
    reconnection_fine: string;
    /** Two-approver threshold for the Arrears Adjustment feature (App\Services\ArrearsAdjustmentService::requiresSecondApproval()). */
    arrears_second_approval_threshold: string;
    /** RCCM — Registre du Commerce et du Crédit Mobilier (OHADA commercial registration number). */
    rccm_number: string | null;
    /** NIU — Numéro d'Identifiant Unique (Cameroon DGI taxpayer ID). */
    niu: string | null;
    /** Media-library-backed logo URL (Company::logoDataUri()'s counterpart for the browser), null if none uploaded. */
    logo_url: string | null;
}

export interface NotificationSettings {
    uuid: string;
    whatsapp_enabled: boolean;
    email_enabled: boolean;
    sms_enabled: boolean;
    twilio_account_sid: string | null;
    twilio_auth_token: string | null;
    twilio_whatsapp_number: string | null;
}

export interface TenantUserRow {
    id: number;
    role: Role;
    /** Purely descriptive label (e.g. "Recovery Coordinator") — separate from `role`, which drives permissions. */
    job_title: string | null;
    name: string;
    username: string;
    email: string;
    status: 'active' | 'passive';
    /**
     * Multi-branch RBAC fence (branches-and-locations.md section 4). null
     * means unrestricted (sees every branch) — the default for everyone.
     * Only meaningful/editable once the tenant has 2+ branches; see
     * Settings/Users.tsx's conditional rendering.
     */
    branch_uuid?: string | null;
    branch_name?: string | null;
    /**
     * Narrow per-user payment-recording grant — only meaningful when
     * `role === 'worker'` (see app/Policies/PaymentPolicy.php's
     * create() doc comment). Every other role already has payments.create
     * via role, so Settings/Users.tsx only renders the toggle for worker
     * rows.
     */
    can_record_payments: boolean;
    /**
     * Investor tier grant (app/Policies/ReportPolicy.php's view() doc
     * comment) — a pure additive OR with no role restriction implied
     * (unlike can_record_payments, which only makes sense on a worker
     * row). Settings/Users.tsx renders this checkbox on every row.
     */
    is_investor: boolean;
}

export type CommandRunStatus = 'queued' | 'pending_review' | 'published' | 'failed' | 'rolled_back';

/**
 * Aggregate stats only (App\Services\ManuscriptGenerationBatchService::
 * aggregateComputedResult()) — never the full per-customer
 * computed_result.customers map, which SettingsCommandRunController::index()
 * deliberately excludes from the page payload (up to ~550 entries; the
 * Preview UI is a summary, matching how `metadata` is shown, not a raw
 * per-customer dump).
 */
export interface CommandRunComputedResultSummary {
    customers_processed: number;
    frozen_customers: number;
    total_arrears_sum: number;
    total_credit_sum: number;
    total_bill_sum: number;
    errors: number;
    error_details: Array<{ customer_id: number; customer_uuid: string; message: string }>;
}

/** job_batches progress (Illuminate\Bus\Batch) for a still-`queued` run. */
export interface CommandRunBatchProgress {
    total: number;
    pending: number;
    failed: number;
    finished: boolean;
}

export interface CommandRunEntry {
    uuid: string;
    command: string;
    period: string;
    ran_at: string;
    metadata: Record<string, unknown> | null;
    status: CommandRunStatus;
    computed_result_summary: CommandRunComputedResultSummary | null;
    batch_progress: CommandRunBatchProgress | null;
    published_at: string | null;
    /** ManuscriptRunLockService::isPeriodLocked() — true once this run's period has passed. A display hint only; the backend enforces the same check independently on every action. */
    is_locked: boolean;
}

/** One downloadable artifact from an async bill-generation run (App\Services\BillBatchService). */
export interface BillBatchFile {
    uuid: string;
    kind: 'zone' | 'bulk' | 'zip';
    zone_name: string | null;
    bill_count: number;
    page_count: number | null;
    size_bytes: number;
    download_url: string;
}

/** An asynchronous (queued) bill-generation run for a period (owner's 2026-08-30 ask). */
export interface BillBatch {
    uuid: string;
    status: 'queued' | 'processing' | 'completed' | 'partial' | 'failed' | 'cancelled';
    period: string;
    density: number;
    template: string;
    total_bills: number;
    total_zones: number;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
    files: BillBatchFile[];
}

/** Settings > Command Runs' manuscript_generation schedule config (task-scheduler.md section 4). */
export interface ManuscriptSchedule {
    enabled: boolean;
    day_of_month: number;
    last_run_at: string | null;
    next_run_at: string | null;
}

export interface ExpenseCategory {
    uuid: string;
    name: string;
    icon: string | null;
    is_active: boolean;
    sort_order: number;
}

export interface Expenditure {
    uuid: string;
    category_uuid: string;
    category_name: string;
    amount: string;
    description: string | null;
    spent_at: string;
    notes: string | null;
    recorded_offline: boolean;
    recorded_by_name: string;
    created_at: string;
}

export interface DashboardIncome {
    total: string;
    verified: string;
    pending_verification: string;
    rejected: string;
    payment_count: number;
}

export interface DashboardExpenseCategoryBreakdown {
    name: string;
    amount: string;
    count: number;
}

export interface DashboardExpenses {
    total: string;
    by_category: DashboardExpenseCategoryBreakdown[];
}

export interface DashboardPnl {
    net: string;
    margin_pct: number;
}

export interface DashboardBudgetVariance {
    category: string;
    budgeted: string;
    actual: string;
    variance: string;
    variance_pct: number;
}

export interface ResourcesDashboard {
    period: string;
    income: DashboardIncome;
    expenses: DashboardExpenses;
    pnl: DashboardPnl;
    budgets: DashboardBudgetVariance[];
}

export type ReportTier = 'daily' | 'weekly' | 'monthly';

export interface ReportDelta {
    pct: number;
    direction: 'up' | 'down' | 'flat';
}

export interface ReportPaymentsBreakdown {
    total: string;
    verified: string;
    pending: string;
    rejected: string;
    count: number;
}

export interface ReportPendingQueueItem {
    uuid: string;
    customer_name: string;
    amount: string;
    age_hours: number;
}

export interface ReportPendingQueue {
    count: number;
    total: string;
    oldest_age_hours: number | null;
    oldest_created_at: string | null;
    items: ReportPendingQueueItem[];
}

export interface ReportPaymentRow {
    uuid: string;
    customer_name: string;
    zone_name: string;
    amount: string;
    verification_status: VerificationStatus;
    created_at: string;
}

export interface ReportVerificationsActioned {
    approved: number;
    rejected: number;
}

export interface ReportStatusChange {
    from: string | null;
    to: string | null;
    count: number;
}

export interface ReportOfflineSyncDevice {
    device: string | null;
    count: number;
    total: string;
}

export interface ReportOfflineSync {
    count: number;
    total: string;
    by_device: ReportOfflineSyncDevice[];
}

export interface DailyReport {
    tier: 'daily';
    date: string;
    label: string;
    is_current: boolean;
    payments: ReportPaymentsBreakdown;
    payments_today: ReportPaymentRow[];
    pending_queue: ReportPendingQueue;
    verifications_actioned: ReportVerificationsActioned;
    new_customers: { count: number };
    status_changes: ReportStatusChange[];
    expenditures: { count: number; total: string };
    offline_sync: ReportOfflineSync;
}

export interface ReportLeagueRow {
    zone_uuid: string;
    zone_name: string;
    collected: string;
    expected: string;
    ratio_pct: number;
}

export interface ReportSlaRow {
    branch_name: string;
    count: number;
    total: string;
}

export interface WeeklyReport {
    tier: 'weekly';
    week_start: string;
    week_end: string;
    label: string;
    is_current: boolean;
    collections: { total: string; verified: string; count: number };
    daily_breakdown: ReportTrendPoint[];
    new_customers: number;
    net_disconnections: number;
    deltas: {
        collections_total: ReportDelta | null;
        payment_count: ReportDelta | null;
        new_customers: ReportDelta | null;
        net_disconnections: ReportDelta | null;
    };
    league_table: ReportLeagueRow[];
    verification_sla: ReportSlaRow[];
}

export interface ReportBillingLedger {
    ran_at: string | null;
    customers_processed: number | null;
    frozen_customers: number | null;
    total_bill_sum: string | null;
    total_arrears_sum: string | null;
    total_credit_sum: string | null;
    errors: number | null;
    error_details: Array<Record<string, unknown>>;
    duration_ms: number | null;
}

export interface ReportArrearsAging {
    '1x': number;
    '2x': number;
    '3x_plus': number;
}

export interface ReportCollectionHealth {
    collection_rate: number;
    total_collected: string;
    total_bill: string;
    arrears_aging: ReportArrearsAging;
}

export interface ReportTrendPoint {
    date: string;
    verified: string;
    payment_count: number;
}

export interface ReportArrearsAdjustmentsWrittenOff {
    count: number;
    total: string;
}

export interface MonthlyReport {
    tier: 'monthly';
    period: string;
    label: string;
    is_current: boolean;
    collections_cash_received: ReportPaymentsBreakdown;
    arrears_adjustments_written_off: ReportArrearsAdjustmentsWrittenOff;
    billing_ledger: ReportBillingLedger | null;
    collection_health: ReportCollectionHealth;
    trend: ReportTrendPoint[];
    pnl?: ResourcesDashboard;
}

export type ReportData = DailyReport | WeeklyReport | MonthlyReport;

export type RegistrationStatus = 'pending' | 'approved' | 'rejected';

export interface LandlordTenant {
    id: string;
    name: string;
    slug: string;
    domain: string | null;
    is_active: boolean;
    registration_status: RegistrationStatus;
    rejection_reason: string | null;
    bulk_whatsapp_enabled: boolean;
    created_at: string | null;
}

export interface Agent {
    uuid: string;
    name: string;
    location: string;
    phone: string;
    zone_uuid: string;
    zone_name: string | null;
    salary: string;
    email: string | null;
    dob: string | null;
    marital_status: 'yes' | 'no' | null;
    children: number | null;
    status: 'active' | 'inactive';
    last_sync_at: string | null;
}

export type ComplaintCategory = 'operational' | 'customer';
export type ComplaintStatus = 'open' | 'in_progress' | 'resolved';

/**
 * Mirrors App\Http\Controllers\ComplaintController::formatComplaint() /
 * Api\ComplaintController's ComplaintResource. `escalated_at` is always
 * null today — nothing writes it yet (the escalation engine,
 * references/task-scheduler.md section 5, is a separate later build) — but
 * the field is here now so that work is a data-wiring change, not a
 * frontend rewrite. See resources/tsx/lib/complaintState.ts for how the
 * 5-state Badge treatment (references/complaint-desk.md section 6) is
 * derived from `status` + `created_at` + `escalated_at`.
 */
export interface Complaint {
    uuid: string;
    category: ComplaintCategory;
    title: string;
    description: string;
    urgent: boolean;
    status: ComplaintStatus;
    customer_uuid: string | null;
    customer_name: string | null;
    zone_name: string | null;
    submitted_by_name: string | null;
    assigned_to_name: string | null;
    resolved_by_name: string | null;
    resolved_at: string | null;
    resolution_notes: string | null;
    escalated_at: string | null;
    duplicate_of_uuid: string | null;
    duplicate_of_title?: string | null;
    created_at: string;
}

export interface ComplaintDuplicateCandidate {
    uuid: string;
    title: string;
    status: ComplaintStatus;
    submitted_by_name: string | null;
    created_at: string;
}

export interface ComplaintDashboardStats {
    open: number;
    approaching_deadline: number;
    escalated: number;
    resolved_this_week: number;
}

export type AuditAction = 'create' | 'update' | 'delete';

export interface AuditLogUser {
    uuid: string;
    name: string;
}

export interface AuditLogEntry {
    id: number;
    table_name: string;
    record_uuid: string;
    action: AuditAction;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    user: AuditLogUser | null;
    ip_address: string | null;
    device_id: string | null;
    created_at: string;
    summary: string;
}

export interface AuditLogFilters {
    table_name?: string | null;
    action?: AuditAction | null;
    user_uuid?: string | null;
    search?: string | null;
    record_uuid?: string | null;
    from?: string | null;
    to?: string | null;
}
