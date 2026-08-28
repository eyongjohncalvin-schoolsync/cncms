/**
 * TypeScript interfaces for the CNCMS API request/response shapes this app
 * talks to. Hand-copied from the real backend (Laravel controllers/requests
 * under app/Http/Controllers/Api, app/Http/Requests) rather than shared via
 * a monorepo package — see
 * .claude/skills/cncms-context/references/mobile-app-react-native.md §1 for
 * why that's a deliberate choice at this project's size.
 *
 * Every shape below was confirmed against the real running backend
 * (127.0.0.1:8124) during this build, not just read from source — see the
 * verification notes in the phase-1 report.
 */

// ---------------------------------------------------------------------------
// Auth — app/Http/Controllers/Api/AuthController.php
// ---------------------------------------------------------------------------

export interface LoginResponse {
    user: {
        uuid: string;
        name: string;
        username: string;
        email: string;
        /**
         * Display-only. Do NOT use this for permission logic — the login
         * endpoint resolves it best-effort before ResolveTenant middleware
         * has even run. Always follow login with GET /auth/me for the
         * authoritative role. See mobile-app-react-native.md §7.
         */
        role: TenantRole | null;
    };
    token: string;
}

export type TenantRole = 'super' | 'admin' | 'manager' | 'agent' | 'worker';

export interface MeResponse {
    user: {
        uuid: string;
        name: string;
        username: string;
        email: string;
    };
    /** The authoritative role — resolved by ResolveTenant + TenantContext. */
    role: TenantRole;
}

/** PATCH /auth/profile — App\Http\Requests\UpdateProfileRequest. Every field
 * is optional on the wire ('sometimes' server-side), but the mobile "Edit
 * profile" form always sends all three. */
export interface UpdateProfilePayload {
    name?: string;
    username?: string;
    email?: string;
}

export interface UpdateProfileResponse {
    user: {
        uuid: string;
        name: string;
        username: string;
        email: string;
    };
}

/** PATCH /auth/password — App\Http\Requests\UpdatePasswordRequest. */
export interface UpdatePasswordPayload {
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
}

// ---------------------------------------------------------------------------
// Sync — app/Services/SyncService.php, app/Http/Requests/SyncPushRequest.php
// ---------------------------------------------------------------------------

export type PaymentFrequency = 'monthly' | 'yearly' | 'months';

export interface SyncPushPaymentItem {
    local_uuid: string;
    customer_uuid: string;
    amount: number;
    credit?: number | null;
    frequency: PaymentFrequency;
    months?: number | null;
    created_at?: string | null;
}

export interface SyncPushExpenditureItem {
    local_uuid: string;
    category_uuid: string;
    amount: number;
    description?: string | null;
    spent_at: string;
    notes?: string | null;
}

/**
 * complaint-desk.md section 7 — App\Http\Requests\SyncPushRequest's
 * `changes.complaints.*` rules, App\Services\SyncService::pushComplaint().
 * No `urgent`-scale/priority field beyond the plain boolean fast-path
 * toggle, and no photo — see LocalComplaint's doc comment in
 * src/types/db.ts for why photo is deliberately not built yet.
 */
export interface SyncPushComplaintItem {
    local_uuid: string;
    category: 'operational' | 'customer';
    title: string;
    description: string;
    urgent?: boolean;
    /** Required server-side iff category === 'customer' — enforced by
     * ComplaintService::resolveCustomer(), not re-validated on this type. */
    customer_uuid?: string | null;
    created_at?: string | null;
}

export interface SyncPushRequestBody {
    device_id: string;
    last_sync_at?: string | null;
    changes: {
        payments?: SyncPushPaymentItem[];
        expenditures?: SyncPushExpenditureItem[];
        complaints?: SyncPushComplaintItem[];
    };
}

export interface SyncPushResultItem {
    local_uuid: string;
    server_uuid?: string;
    status: 'synced' | 'failed';
    error?: string;
}

export interface SyncPushResponse {
    status: 'success';
    synced_at: string;
    results: {
        payments: SyncPushResultItem[];
        expenditures: SyncPushResultItem[];
        complaints: SyncPushResultItem[];
    };
    errors: Array<{ entity_type: string; local_uuid: string | null; message: string }>;
}

export interface SyncPullCustomer {
    uuid: string;
    name: string;
    phone: string | null;
    /** Numeric string as returned by the API (Postgres DECIMAL cast to string by Eloquent). */
    bill: string;
    location: string | null;
    level: string | null;
    status: string;
    zone_uuid: string | null;
    /**
     * From the customer's `latestManuscript` (App\Models\Customer),
     * eager-loaded alongside `zone` in SyncService::upsertedCustomers() —
     * added so the Customers list/detail screens can render arrears/credit
     * from the offline cache without a live round trip. `null` for a
     * customer with no manuscript row yet (e.g. never billed).
     */
    total_arrears: string | null;
    credit: string | null;
}

export interface SyncPullChangedPayment {
    uuid: string;
    customer_uuid: string | null;
    amount: string;
    verification_status: 'verified' | 'rejected';
    /** Only present when verification_status === 'rejected'. */
    rejection_reason?: string | null;
}

/**
 * App\Http\Resources\NotificationResource's shape, as returned inside
 * SyncService::pull()'s `changes.notifications` block (in-app-
 * notifications.md section 6 / complaint-desk.md section 7). `read_at`/
 * `acknowledged_at` are this caller's own per-recipient state (lazily
 * materialized server-side — in-app-notifications.md section 3), not
 * properties of the notification event itself.
 */
export interface SyncPullNotificationItem {
    uuid: string;
    type: string;
    severity: 'info' | 'warning' | 'urgent' | 'emergency';
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
 * App\Services\NotificationService::feedForUser()'s exact return shape —
 * `items` is this user's recent feed (bounded, newest first), `emergency`
 * is every currently-unacknowledged severity='emergency' notification in
 * this user's audience (NOT bounded by the same limit as `items`, so an
 * older emergency can appear here even if it's aged out of `items`) —
 * see NotificationRepository::unacknowledgedEmergenciesForUser().
 */
export interface SyncPullNotifications {
    items: SyncPullNotificationItem[];
    unread_count: number;
    emergency: SyncPullNotificationItem[];
}

export interface SyncPullResponse {
    synced_at: string;
    changes: {
        customers: {
            upserted: SyncPullCustomer[];
            deleted: string[];
        };
        payments: {
            verified: SyncPullChangedPayment[];
            rejected: SyncPullChangedPayment[];
        };
        notifications: SyncPullNotifications;
    };
}

export interface SyncStatusResponse {
    device_id: string | null;
    last_sync_at: string | null;
    pending_push: number;
    pending_pull: number;
    failed_items: number;
}

export type ReceiptEntityType = 'payment' | 'expenditure';

/**
 * POST /api/v1/sync/upload-receipt — a separate multipart upload, sent only
 * once the owning record has synced and has a server_uuid (see
 * offline-sync-strategy.md §4.4 and
 * App\Http\Controllers\Api\SyncController::uploadReceipt()). `receipt_url`
 * is the full public URL, not a raw storage path — stored as-is in
 * payments.receipt_server_path for later display.
 */
export interface UploadReceiptResponse {
    message: string;
    receipt_url: string;
}

/**
 * POST /api/v1/notifications/{uuid}/acknowledge —
 * App\Http\Controllers\Api\NotificationController::acknowledge(). The
 * real online action complaint-desk.md section 7's emergency interrupt
 * screen calls — never a local-only dismiss. `acknowledged_at` reflects
 * the actual confirmed server timestamp (idempotent: acknowledging an
 * already-acknowledged notification returns the original timestamp, not a
 * new one).
 */
export interface AcknowledgeNotificationResponse {
    uuid: string;
    acknowledged_at: string;
}

// ---------------------------------------------------------------------------
// Customer detail — GET/PATCH /api/v1/customers/{uuid}, app/Http/Resources/
// CustomerResource.php, app/Http/Requests/ReconnectCustomerRequest.php,
// app/Services/CustomerStatusService.php. Fetched live (not from the local
// SQLite cache) because arrears/credit/reconnection_fine are server-computed
// and only the show endpoint's `manuscript`/`reconnection_fine` fields carry
// them at full precision — see mobile-app-react-native.md §4's Customer
// Detail screen.
// ---------------------------------------------------------------------------

export interface CustomerManuscriptSummary {
    uuid: string;
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    period: string;
}

export interface CustomerRecentPaymentApi {
    uuid: string;
    amount: string;
    frequency: PaymentFrequency;
    verification_status: 'pending' | 'verified' | 'rejected';
    created_at: string;
}

export interface CustomerDetailApi {
    uuid: string;
    name: string;
    phone: string | null;
    zone_uuid: string | null;
    zone_name: string | null;
    bill: string;
    others: string;
    level: string | null;
    status: string;
    status_reason: string | null;
    status_note: string | null;
    location: string | null;
    created_at: string;
    manuscript: CustomerManuscriptSummary | null;
    recent_payments: CustomerRecentPaymentApi[];
    /**
     * Admin-configurable (Settings > Company Info), present when `status`
     * is 'disconnected' OR 'suspended' — CustomerStatusService::
     * reconnectOne()'s $includeFine parameter makes the fine
     * admin-discretion opt-in for either status (2026-08 owner decision),
     * so this represents what WOULD be charged if the office opts in, not
     * an automatic charge. `null` otherwise. See CustomerResource's doc
     * comment.
     */
    reconnection_fine: string | null;
}

export interface CustomerDetailResponse {
    data: CustomerDetailApi;
}

/**
 * PATCH /api/v1/customers/{uuid}/reconnect body — mirrors
 * ReconnectCustomerRequest exactly. `include_fine` (2026-08 owner decision,
 * business-rules.md section 6) is a plain optional boolean, defaulting to
 * false/unchecked — the reconnection fine is admin discretion, never
 * required or automatic, for EITHER 'disconnected' or 'suspended'.
 * `arrears_payment` is an optional decimal STRING (not a number — matches
 * the `decimal:0,2` validation rule server-side), single-customer only.
 */
export interface ReconnectCustomerRequestBody {
    note?: string;
    include_fine?: boolean;
    arrears_payment?: string;
}

export interface ReconnectCustomerResponse {
    data: CustomerDetailApi;
    message: string;
}

/**
 * PATCH /api/v1/customers/{uuid}/disconnect body — mirrors
 * DisconnectCustomerRequest exactly. `note` is optional free text; the
 * reason is implicitly "non-payment" server-side (business-rules.md
 * section 1), never a reason picker like suspend's. 2026-08 mobile
 * field-ops widening: App\Policies\CustomerPolicy::disconnect() now admits
 * an `agent` scoped to their own zone, alongside the unrestricted
 * super/admin/manager — see that policy method's doc comment.
 */
export interface DisconnectCustomerRequestBody {
    note?: string;
}

export interface DisconnectCustomerResponse {
    data: CustomerDetailApi;
    message: string;
}

// ---------------------------------------------------------------------------
// Disconnection eligibility — GET /api/v1/customers/eligible-for-disconnection,
// App\Http\Controllers\Api\CustomerController::eligibleForDisconnection(),
// App\Services\CustomerEligibilityService::shape(). The mobile JSON
// counterpart of the web Disconnections page's `?eligible=1` tab. An
// `agent` caller is always force-scoped server-side to their own zone
// (App\Support\TenantContext::zoneId) regardless of any zone_uuid this
// client might send — see the controller's doc comment.
// ---------------------------------------------------------------------------

export interface EligibleCustomerApi {
    uuid: string;
    name: string;
    phone: string | null;
    zone_uuid: string | null;
    zone_name: string | null;
    /** Numeric string, DECIMAL(12,2) cast to string by Eloquent. */
    bill: string;
    others: string;
    level: string | null;
    status: string;
    status_reason: string | null;
    status_note: string | null;
    location: string | null;
    /** Numeric string — accumulated prior arrears (not the fresh current-cycle charge). */
    total_arrears: string;
    /** Display-only ("2.8x bill") — never itself a threshold decision. */
    arrears_ratio: number;
    months_overdue: number;
}

export interface EligibleForDisconnectionResponse {
    data: EligibleCustomerApi[];
}

// ---------------------------------------------------------------------------
// Manuscript (current-period billing register) — GET /api/v1/manuscripts,
// App\Http\Controllers\Api\ManuscriptController::index(), App\Http\Resources\
// ManuscriptResource.php, App\Services\ManuscriptService::scopedFilters()/
// summaryFor(). Backs app/manuscript.tsx.
//
// PERIOD — always sent explicitly by the client as 'YYYY-MM' (see
// src/api/manuscripts.ts's currentPeriod()); the server independently
// defaults+validates the same way (Carbon::now()->format('Y-m'), rejecting
// anything not matching /^\d{4}-(0[1-9]|1[0-2])$/) — this is deliberately
// NOT the `latestManuscript`/`latestOfMany('period')` relationship, which a
// real 2026-08 incident showed can silently trust a bogus future period
// (see Customer::latestManuscript()'s own doc comment). Never build a screen
// against "latest manuscript of any period" — always an explicit,
// server-validated period.
//
// ZONE — an `agent` caller is force-scoped server-side to their own zone
// (TenantContext::zoneId) regardless of any zone_uuid sent, mirroring
// CustomerController::eligibleForDisconnection()'s pre-existing pattern
// (added to ManuscriptController::index() alongside this screen — see
// mobile-app-react-native.md §13). Office roles (manager/admin/super) are
// unscoped by default, matching the web Manuscripts register.
// ---------------------------------------------------------------------------

export interface ManuscriptListItemApi {
    customer_uuid: string;
    customer_name: string;
    zone_name: string | null;
    /** Numeric strings — DECIMAL(12,2) cast to string by Eloquent. */
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    period: string;
    status: string;
}

/**
 * App\Services\ManuscriptRepository::aggregates()'s exact scoping: `bill`/
 * `arrears`/`credit`/`collected`/`collection_rate` below cover ACTIVE
 * customers only (a disconnected/passive/suspended customer's dormant old
 * balance is deliberately excluded from "how much is billed/owed right
 * now"). `total_customers`, by contrast, counts every customer with a
 * manuscript row this period regardless of status — so it will not always
 * equal the number of rows actually summed into the money figures above it.
 * See manuscript.tsx's footnote, which discloses this rather than silently
 * implying the two always agree.
 */
export interface ManuscriptSummaryApi {
    total_customers: number;
    total_bill: string;
    total_arrears: string;
    total_credit: string;
    total_collected: string;
    /** Already rounded to 1 decimal server-side. */
    collection_rate: number;
}

export interface ManuscriptIndexResponse {
    data: ManuscriptListItemApi[];
    period: string;
    summary: ManuscriptSummaryApi;
    meta?: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

// ---------------------------------------------------------------------------
// Bill WhatsApp message — GET /api/v1/bills/{uuid}/whatsapp-message,
// app/Http/Controllers/Api/BillController.php::whatsappMessage(),
// app/Services/BillNotificationService.php. Manual (free, no-Twilio) mode
// only — see bill-notifications.md §1. `phone` is already normalized to
// wa.me's required digits-only, '237'-prefixed international form; the
// mobile client builds the actual wa.me link itself (see
// src/utils/whatsapp.ts) rather than receiving a ready-made one.
// ---------------------------------------------------------------------------

/**
 * 'no_phone' — no phone on file, or it doesn't normalize to a plausible
 * Cameroon mobile number (~78% of legacy customers — see
 * database-schema.md's known data-quality issues). 'no_manuscript' — the
 * customer has no bill calculated yet for any period. `null` when
 * `available` is true. Never a raw HTTP error for either case — the mobile
 * UI keys off this field to show a clear disabled/explanatory state.
 */
export type BillWhatsappMessageUnavailableReason = 'no_phone' | 'no_manuscript' | null;

export interface BillWhatsappMessageApi {
    has_phone: boolean;
    available: boolean;
    reason: BillWhatsappMessageUnavailableReason;
    /** Digits-only, '237'-prefixed international form, or null. */
    phone: string | null;
    message: string | null;
}

export interface BillWhatsappMessageResponse {
    data: BillWhatsappMessageApi;
}

// ---------------------------------------------------------------------------
// Expense categories — GET /api/v1/resources/categories
// ---------------------------------------------------------------------------

export interface ExpenseCategoryApi {
    uuid: string;
    name: string;
    icon: string | null;
    is_active: boolean;
    sort_order: number;
}

export interface ExpenseCategoryListResponse {
    data: ExpenseCategoryApi[];
}

// ---------------------------------------------------------------------------
// Arrears Adjustment — POST /api/v1/arrears-adjustments,
// App\Http\Controllers\Api\ArrearsAdjustmentController::store(),
// App\Http\Requests\StoreArrearsAdjustmentRequest,
// App\Http\Resources\ArrearsAdjustmentResource. Hand-copied 2026-08-28 for
// the mobile "Request an arrears adjustment" screen — see
// .claude/skills/cncms-context/references/arrears-adjustment.md. This is
// the REQUEST side only: mobile never calls approve()/reject() (no JSON
// route exists for either — see the controller's own class doc), so no
// approval-related fields are represented here.
// ---------------------------------------------------------------------------

export type ArrearsAdjustmentDirection = 'decrease' | 'increase';

export type ArrearsAdjustmentReasonCategory =
    | 'legacy_migration_error'
    | 'billing_error'
    | 'goodwill_service_outage'
    | 'bad_debt_writeoff'
    | 'credit_clawback'
    | 'other';

export type ArrearsAdjustmentStatus = 'pending' | 'pending_second_approval' | 'approved' | 'rejected';

/** Request body — mirrors StoreArrearsAdjustmentRequest::rules() exactly.
 * `amount` is a decimal STRING (matches the server's `decimal:0,2` rule),
 * same convention as ReconnectCustomerRequestBody::arrears_payment. */
export interface RequestArrearsAdjustmentPayload {
    customer_uuid: string;
    target_period: string;
    direction: ArrearsAdjustmentDirection;
    amount: string;
    reason_category: ArrearsAdjustmentReasonCategory;
    reason_note: string;
    complaint_uuid?: string;
}

export interface ArrearsAdjustmentApi {
    uuid: string;
    customer_uuid: string;
    customer_name: string;
    target_period: string;
    direction: ArrearsAdjustmentDirection;
    amount: string;
    reason_category: ArrearsAdjustmentReasonCategory;
    reason_note: string;
    arrears_snapshot: string;
    status: ArrearsAdjustmentStatus;
    requested_by: { uuid: string; name: string } | null;
    created_at: string;
}

export interface RequestArrearsAdjustmentResponse {
    data: ArrearsAdjustmentApi;
}

// ---------------------------------------------------------------------------
// Generic API error envelope (401/403/422/500 all share this shape)
// ---------------------------------------------------------------------------

export interface ApiErrorBody {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
}
