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
import type { InstallationRequestFields } from '../utils/installation';
import type { FieldInstallationItem } from '../utils/fieldInstallation';

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

export type TenantRole = 'super' | 'admin' | 'manager' | 'agent' | 'cashier' | 'worker';

export interface MeResponse {
    user: {
        uuid: string;
        name: string;
        username: string;
        email: string;
    };
    /** The authoritative role — resolved by ResolveTenant + TenantContext. */
    role: TenantRole;
    /**
     * RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): the permission
     * strings this role grants, or `['*']` for a super role. Since Wave 4
     * this is what the mobile guards check (via AuthContext's `can()`), not
     * `role` — cached alongside `role` in the offline session profile and
     * refreshed on every `/auth/me` (login + cold start).
     */
    permissions: string[];
}

/** PATCH /auth/profile — App\Http\Requests\UpdateProfileRequest. Every field
 * is optional on the wire ('sometimes' server-side). `email` is accepted
 * only unchanged: a new address goes through POST /auth/email + a code. */
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

// ---------------------------------------------------------------------------
// Account security — App\Http\Controllers\Api\{AuthController,
// TwoFactorChallengeController, LoginCodeController, AccountSecurityController}
// ---------------------------------------------------------------------------

export type TwoFactorMethod = 'totp' | 'recovery' | 'email';

/**
 * POST /auth/login (or /auth/login-code/verify) for a user with two-factor
 * on: NO token yet — send `challenge_token` + a code to POST /auth/two-factor.
 * `methods` lacks 'email' when the first factor was itself an email code.
 */
export interface TwoFactorChallengeResponse {
    two_factor_required: true;
    challenge_token: string;
    methods: TwoFactorMethod[];
    email_hint: string;
    expires_in: number;
}

export type LoginResult = LoginResponse | TwoFactorChallengeResponse;

/** GET /auth/security */
export interface SecurityOverview {
    email: string;
    pending_email: string | null;
    two_factor: {
        enabled: boolean;
        pending_setup: boolean;
        recovery_codes_remaining: number;
    };
}

/** POST /auth/two-factor/setup — `qr_code` is a PNG data URI. */
export interface TwoFactorSetup {
    secret: string;
    otpauth_url: string;
    qr_code: string;
}

/** GET /auth/sessions */
export interface SessionsResponse {
    sessions: {
        ip_address: string | null;
        user_agent: string | null;
        last_active_at: string;
        is_current: boolean;
    }[];
    devices: {
        id: number;
        name: string;
        last_used_at: string | null;
        created_at: string | null;
        is_current: boolean;
    }[];
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
    /** Draw-down Q1 — agent's "pay down arrears first" toggle for a
     * months/yearly prepayment (references/prepayment-drawdown.md). */
    clear_arrears_first?: boolean | null;
    created_at?: string | null;
    /** Cash controls (docs/plans/cash-controls.md) — omitted = cash. */
    method?: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
    method_reference?: string | null;
    /** The receipt this phone printed, checked server-side by FieldReceiptService. */
    receipt_number?: string | null;
    security_code?: string | null;
    receipt_issued_at?: string | null;
    /** Why a second payment for the customer this month is fine (PaymentService::flagPossibleDuplicate()). */
    duplicate_reason?: 'advance' | 'arrears' | 'refund' | 'other' | null;
    duplicate_note?: string | null;
    /** A prepayment offer sold in the field (PaymentService::applyPrepaymentOffer()). */
    prepayment_offer_uuid?: string | null;
}

/**
 * "Request a bon" (docs/plans/expenditures-and-spending-controls.md, item 2)
 * — App\Http\Requests\SyncPushRequest's `changes.bon_requests.*`; becomes a
 * pending bon by this agent (App\Services\SyncService::pushBonRequest()).
 */
export interface SyncPushBonRequestItem {
    local_uuid: string;
    category_uuid: string;
    amount: number;
    payee_name: string;
    purpose: string;
    needed_on: string;
    from_collections: boolean;
    created_at?: string | null;
}

/** Older builds' "Record expense" item — still accepted, turned into a pending bon server-side. */
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
        bon_requests?: SyncPushBonRequestItem[];
        expenditures?: SyncPushExpenditureItem[];
        complaints?: SyncPushComplaintItem[];
        /** Customers registered in the field, offline (installation phase 3). */
        installations?: FieldInstallationItem[];
        /** Payments cancelled on this phone before they synced (owner, 2026-10-28). */
        payment_cancellations?: SyncPushPaymentCancellationItem[];
    };
}

/**
 * A payment cancelled on the phone before it synced — App\Services\
 * SyncService::pushPaymentCancellation(): its receipt number is cancelled,
 * or, if the payment reached the server after all, the office gets a
 * reversal request.
 */
export interface SyncPushPaymentCancellationItem {
    local_uuid: string;
    receipt_number: string | null;
    reason: string;
}

/** `cancelled` — done; `requested` — it had reached the server, the office decides; `failed` — retried next sync. */
export interface SyncPushCancellationResult {
    local_uuid: string;
    status: 'cancelled' | 'requested' | 'failed';
    error?: string;
}

/** GET /payments/by-others — this month's payments in the agent's zones someone else took (Api\PaymentController::byOthers()). */
export interface PaymentByOthersApi {
    uuid: string;
    customer_uuid: string | null;
    customer_name: string | null;
    amount: string;
    method: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
    collected_at: string | null;
    recorded_by: string | null;
    where: 'office' | 'field';
    receipt_number: string | null;
    verification_status: 'pending' | 'verified' | 'rejected';
}

/** One `results.installations[]` entry — `server_uuid` is the customer's uuid. */
export interface SyncPushInstallationResult extends SyncPushResultItem {
    activation?: 'pending' | 'activated' | 'rejected';
    possible_duplicate?: boolean;
}

/** `changes.installations[]` on the pull — the office's decision on this user's registrations. */
export interface SyncPullInstallationDecision {
    local_uuid: string;
    customer_uuid: string | null;
    customer_name: string | null;
    status: 'pending' | 'activated' | 'rejected';
    reason: string | null;
    possible_duplicate: boolean;
    decided_at: string | null;
}

export interface SyncPushResultItem {
    local_uuid: string;
    server_uuid?: string;
    status: 'synced' | 'failed';
    error?: string;
    /** A bon request's BC- number. */
    number?: string;
}

export interface SyncPushResponse {
    status: 'success';
    synced_at: string;
    results: {
        payments: SyncPushResultItem[];
        /** Absent on servers older than the bon-request change. */
        bon_requests?: SyncPushResultItem[];
        expenditures: SyncPushResultItem[];
        complaints: SyncPushResultItem[];
        /** Absent on servers older than installation phase 3. */
        installations?: SyncPushInstallationResult[];
        /** Absent on servers older than the cancel-before-sync change. */
        payment_cancellations?: SyncPushCancellationResult[];
    };
    errors: Array<{ entity_type: string; local_uuid: string | null; message: string }>;
}

/**
 * One line of a customer's ticked services (services.md section 6) — a
 * plain service, or one of its "options" (variants — section 4, e.g. a
 * specific TV channel broadcast) when `service_variant_uuid` is set. Same
 * field names server-side and on both API resources
 * (CustomerResource/App\Http\Resources\ServiceCatalogueResource) and the
 * pull() payload, so this one shape covers all of them.
 */
export interface CustomerServiceApi {
    service_uuid: string;
    service_name: string;
    service_variant_uuid: string | null;
    service_variant_name: string | null;
    /** Numeric string, e.g. "5000.00". */
    price: string;
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
    /** The customer's branch (offers are sold at its price). Absent from older servers. */
    branch_uuid?: string | null;
    /** Old debt still unpaid, every collector's payments counted (PaymentService::unpaidDebts()). Absent from older servers. */
    unpaid_debt?: string | null;
    /** The latest payment this month by anyone (owner, 2026-10-28). Absent from older servers. */
    paid_this_month?: PaidThisMonthApi | null;
    /**
     * From the customer's `latestManuscript` (App\Models\Customer),
     * eager-loaded alongside `zone` in SyncService::upsertedCustomers() —
     * added so the Customers list/detail screens can render arrears/credit
     * from the offline cache without a live round trip. `null` for a
     * customer with no manuscript row yet (e.g. never billed).
     */
    total_arrears: string | null;
    credit: string | null;
    /**
     * services.md section 6, added 2026-09-03. Customer Detail (app/(tabs)/
     * customers/[uuid].tsx) renders entirely from the local SQLite cache
     * with no live call, so this is the ONLY way that screen ever sees a
     * customer's services — see src/db/customers.ts's `services` column
     * (stored as a JSON-encoded string, parsed on read).
     */
    services: CustomerServiceApi[];
    /**
     * Installation phase 2 promo state (SyncService::upsertedCustomers()):
     * first billed month, FREE vs PREPAID on the latest bill, free months
     * still to come. Absent from older servers.
     */
    billing_starts_period?: string | null;
    /** 'pending' = registered in the field, awaiting the office (phase 3). */
    activation_status?: 'pending' | null;
    is_free?: boolean;
    prepaid_months_remaining?: number;
    service_paid_months?: { service: string; months: number }[];
    free_months?: { service: string; from: string; until: string }[];
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
        /** Absent on servers older than installation phase 3. */
        installations?: SyncPullInstallationDecision[];
    };
}

export interface SyncStatusResponse {
    device_id: string | null;
    last_sync_at: string | null;
    pending_push: number;
    pending_pull: number;
    failed_items: number;
}

export type ReceiptEntityType = 'payment' | 'voucher' | 'expenditure';

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
    /** Draw-down (references/prepayment-drawdown.md): whole billing periods
     * still covered by a months/yearly prepayment, and the rate it was
     * bought at. 0 / null for a customer not in a prepaid window. */
    prepaid_months_remaining: number;
    prepaid_rate: string | null;
    period: string;
}

export interface CustomerRecentPaymentApi {
    uuid: string;
    amount: string;
    frequency: PaymentFrequency;
    /** What the payment was for (server-set, read-only). Absent from older servers = subscription. */
    purpose?: 'subscription' | 'installation' | 'technician_fee';
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
    /** Where emailed receipts go (optional). */
    email: string | null;
    /** How the customer wants their receipts — drives the server's "To send" list. */
    preferred_channel: PreferredChannel;
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
    /**
     * Installation fee still owed (docs/plans/installation-and-promotions.md) —
     * a separate balance, never part of manuscript.total_bill or arrears.
     * Absent from older servers.
     */
    installation_balance?: string;
    /** services.md section 6 — this customer's ticked services/options; `bill` above is their sum. */
    services: CustomerServiceApi[];
    /** Installation phase 2: first billed month and the coverage line. Absent from older servers. */
    billing_starts_period?: string | null;
    coverage?: CustomerCoverageApi;
}

export interface CustomerDetailResponse {
    data: CustomerDetailApi;
}

// ---------------------------------------------------------------------------
// Customer create/edit — POST/PATCH /api/v1/customers[/{uuid}], mirroring
// App\Http\Requests\StoreCustomerRequest / UpdateCustomerRequest exactly
// (services.md sections 6-8). Added 2026-09-03 — customer create/edit
// wasn't on mobile before; every other write action here (payments,
// expenditures, complaints) has its own offline-queue story, but a new
// customer needs a server-issued uuid and a zone/services relationship
// checked against live data, so — like reconnect/disconnect above — this
// is online-only, no offline queue.
// ---------------------------------------------------------------------------

/** One ticked row on the create/edit form — same shape the server expects in `services[]`. */
export interface CustomerServiceSelection {
    service_uuid: string;
    service_variant_uuid: string | null;
    /** Numeric string, e.g. "5000.00" — matches how every other amount field in this app is typed. */
    price: string;
}

export type PreferredChannel = 'whatsapp' | 'email' | 'print';

export interface CreateCustomerRequestBody extends Partial<InstallationRequestFields> {
    zone_uuid: string;
    name: string;
    phone: string;
    location?: string;
    email?: string | null;
    preferred_channel?: PreferredChannel;
    level?: 'normal' | 'Vip' | 'Operator';
    description?: string;
    services: CustomerServiceSelection[];
}

/** All fields optional (StoreCustomerRequest -> UpdateCustomerRequest's `sometimes` rules) — the edit form always sends the full set it has, in practice. */
export type UpdateCustomerRequestBody = Partial<Omit<CreateCustomerRequestBody, keyof InstallationRequestFields>>;

export interface CreateCustomerResponse {
    data: CustomerDetailApi;
    /** Installation phase 2: every payment taken with the save, each with its receipt number. */
    payments?: { uuid: string; amount: string; purpose: string; months: number | null; verification_status: string; receipt_number: string | null }[];
}

// ---------------------------------------------------------------------------
// Installation & first payment (docs/plans/installation-and-promotions.md,
// phase 2) — GET /customers/installation-options, POST
// /customers/installation-preview (App\Services\CustomerInstallationService).
// ---------------------------------------------------------------------------

export interface InstallationOfferApi {
    uuid: string;
    name: string;
    description: string | null;
    fee: string;
    is_default: boolean;
    months: { service_uuid: string; service_name: string; months: number }[];
}

export interface InstallationOptionsResponse {
    data: {
        offers: InstallationOfferApi[];
        max_free_months: number;
        can_manage: boolean;
        can_take_payment: boolean;
        /** May register customers offline in their own zone (phase 3); absent on older servers. */
        can_register_in_field?: boolean;
        today: string;
    };
}

/** One service's timeline — free → paid → billed (revision D15). */
export interface ServiceTimelineApi {
    service_uuid: string;
    service: string;
    price: string;
    paid_amount: string;
    free_from: string | null;
    free_until: string | null;
    paid_from: string | null;
    paid_until: string | null;
    first_billed: string | null;
}

export interface InstallationPreviewApi {
    bill: string;
    fee: string;
    paid_now: string;
    installation_balance: string;
    installation_month: string;
    installation_month_label: string;
    services: ServiceTimelineApi[];
    upfront_amount: string;
    first_bill: string | null;
    first_bill_label: string | null;
    collect_now: string;
    needs_approval: string[];
    max_free_months: number;
    line: string;
    duplicates: { uuid: string; name: string; phone: string | null; zone: string | null; status: string }[];
}

export interface InstallationPreviewResponse {
    data: InstallationPreviewApi;
}

/** The coverage line on the live customer detail (App\Services\CustomerCoverageService). */
export interface CustomerCoverageApi {
    paid_until: string | null;
    first_bill: string | null;
    label: string | null;
}

export interface UpdateCustomerResponse {
    data: CustomerDetailApi;
}

// ---------------------------------------------------------------------------
// Service catalogue — GET /api/v1/services (Api\ServiceController), the
// tick-list the create/edit form renders. Active services + their active
// options only; the edit form merges in whatever the customer already
// holds (from CustomerDetailApi.services) client-side — see
// src/api/services.ts's doc comment.
// ---------------------------------------------------------------------------

export interface ServiceVariantOptionApi {
    uuid: string;
    name: string;
    price: string;
}

/** A customer's latest payment this month, whoever recorded it (sync pull). */
export interface PaidThisMonthApi {
    amount: string;
    collected_at: string;
    recorded_by: string | null;
}

/** GET /customers/{uuid}/payment-check — the payment a new one may repeat, any collector's. */
export interface PaymentCheckApi {
    earlier: {
        uuid: string;
        amount: string;
        collected_at: string;
        recorded_by: string | null;
        recorded_by_me: boolean;
        receipt_number: string | null;
        verification_status: string;
    } | null;
}

/** A price option of a service (Settings → Services) — a quick pick besides its default price. */
export interface ServicePriceOptionApi {
    amount: string;
    label: string | null;
}

export interface ServiceCatalogueApi {
    uuid: string;
    name: string;
    description: string | null;
    price: string;
    /** Quick picks besides `price` (the default); another amount only with `customers.custom_price`. Absent on older servers. */
    price_options?: ServicePriceOptionApi[];
    /** No customer may be set below this (null: no minimum). */
    min_price?: string | null;
    is_default: boolean;
    variants: ServiceVariantOptionApi[];
}

export interface ServiceCatalogueResponse {
    data: ServiceCatalogueApi[];
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
    /** Draw-down prepaid window state — see CustomerManuscriptSummary. */
    prepaid_months_remaining: number;
    prepaid_rate: string | null;
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
export type BillWhatsappMessageUnavailableReason = 'no_phone' | 'no_manuscript' | 'nothing_due' | null;

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

/** Which side of `net = arrears - credit` a correction lands on. Added
 * 2026-08-30 alongside the web ArrearsAdjustmentModal's target toggle — a
 * `credit` correction touches ONLY the loose credit figure, never prepaid
 * coverage (prepaid_months_remaining / prepaid_rate). Optional/defaulted
 * server-side (StoreArrearsAdjustmentRequest: `nullable|in:arrears,credit`),
 * so an older client that never sends it still gets the `arrears` default. */
export type ArrearsAdjustmentTarget = 'arrears' | 'credit';

export type ArrearsAdjustmentReasonCategory =
    | 'legacy_migration_error'
    | 'billing_error'
    | 'goodwill_service_outage'
    | 'bad_debt_writeoff'
    | 'credit_clawback'
    | 'other'
    // Credit-specific categories — offered only when target = 'credit'
    // (StoreArrearsAdjustmentRequest::rules() accepts all nine values).
    | 'credit_correction'
    | 'duplicate_credit'
    | 'migration_credit_error';

export type ArrearsAdjustmentStatus = 'pending' | 'pending_second_approval' | 'approved' | 'rejected';

/** Request body — mirrors StoreArrearsAdjustmentRequest::rules() exactly.
 * `amount` is a decimal STRING (matches the server's `decimal:0,2` rule),
 * same convention as ReconnectCustomerRequestBody::arrears_payment. */
export interface RequestArrearsAdjustmentPayload {
    customer_uuid: string;
    target_period: string;
    /** Omit (or send 'arrears') for an arrears correction — the server
     * defaults it. Send 'credit' to correct the loose credit figure. */
    target?: ArrearsAdjustmentTarget;
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
    target: ArrearsAdjustmentTarget;
    amount: string;
    reason_category: ArrearsAdjustmentReasonCategory;
    reason_note: string;
    arrears_snapshot: string;
    /** Null on older rows / arrears-target requests. */
    credit_snapshot: string | null;
    status: ArrearsAdjustmentStatus;
    requested_by: { uuid: string; name: string } | null;
    created_at: string;
}

export interface RequestArrearsAdjustmentResponse {
    data: ArrearsAdjustmentApi;
}

// ---------------------------------------------------------------------------
// Payment receipts (Wave 2 of payment-receipts-and-whatsapp.md) — read-only
// view + PDF share. GET /api/v1/payments/{uuid}/receipt
// ---------------------------------------------------------------------------

export type PaymentReceiptStatus = 'issued' | 'void';

export interface PaymentReceiptApi {
    uuid: string;
    receipt_number: string;
    status: PaymentReceiptStatus;
    issued_at: string | null;
    amount: string;
    payment_uuid?: string;
    /** Sanctum-token API download endpoint. */
    pdf_url: string;
    /** Signed ~7-day public link — opened in the device browser / WhatsApp. */
    shared_pdf_url: string;
    /** Same link per paper size: A6 sheet, or a 58 / 80 mm pocket-printer roll. */
    shared_pdf_urls: Record<ReceiptFormat, string>;
    /** The bill (invoice) this payment went toward, e.g. "SWECOM-202610-000123". */
    invoice_number: string | null;
    customer_email: string | null;
    /** Why "Email receipt" can't be used right now, or null when it can. */
    email_unavailable_reason: string | null;
}

export type ReceiptFormat = 'a6' | 'thermal58' | 'thermal80';

/** POST /api/v1/payments/{uuid}/receipt/send-email */
export interface SendReceiptEmailResponse {
    message: string;
}

export type BillStatus = 'paid' | 'partly_paid' | 'unpaid' | 'nothing_due';

/** One monthly bill (invoice) — GET /api/v1/bills/{uuid}/history. */
export interface BillHistoryRowApi {
    period: string;
    number: string;
    monthly_charge: string;
    amount_due: string;
    paid: string;
    status: BillStatus;
    /** Positive = owes after this bill, negative = in credit. */
    balance: string;
    prepaid_months_remaining: number;
}

export interface BillHistoryResponse {
    data: BillHistoryRowApi[];
    /** Signed statement-of-account links keyed by months ("6" / "12" / "24"); null without `customers.print_bill`. */
    statement_urls: Record<'6' | '12' | '24', string> | null;
}

export interface PaymentReceiptResponse {
    data: PaymentReceiptApi;
}

/**
 * GET /api/v1/payments/{uuid}/receipt/whatsapp-message (Wave 3) — the two
 * raw ingredients for the manual "Send via WhatsApp" action, same shape as
 * BillWhatsappMessageApi. The client builds the wa.me link itself
 * (src/utils/whatsapp.ts). A voided receipt is a 422, not a `reason` here.
 */
export interface ReceiptWhatsappMessageApi {
    has_phone: boolean;
    available: boolean;
    reason: 'no_phone' | null;
    /** Digits-only, '237'-prefixed international form, or null. */
    phone: string | null;
    message: string | null;
}

export interface ReceiptWhatsappMessageResponse {
    data: ReceiptWhatsappMessageApi;
}

// ---------------------------------------------------------------------------
// Push token registration — POST /api/v1/devices/push-token
// (App\Http\Controllers\Api\PushTokenController, StorePushTokenRequest)
// ---------------------------------------------------------------------------

export interface RegisterPushTokenRequestBody {
    device_id: string;
    expo_push_token: string;
    platform: 'ios' | 'android';
}

export interface RegisterPushTokenResponse {
    uuid: string;
    registered_at: string | null;
}

// ---------------------------------------------------------------------------
// Generic API error envelope (401/403/422/500 all share this shape)
// ---------------------------------------------------------------------------

export interface ApiErrorBody {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
    /** Present with code MAINTENANCE (503) — App\Models\MaintenanceWindow::toNotice(). */
    maintenance?: { starts_at: string; ends_at: string; message: string | null };
}
