/**
 * Local SQLite row shapes — see mobile-app-react-native.md §2 for the
 * schema rationale (payments/expenditures double as outbox + history,
 * customers is a read-only cache, expense_categories is refreshed from the
 * plain REST endpoint not from pull()).
 */

export type SyncStatus = 'queued' | 'syncing' | 'synced' | 'failed';

/** Mirrors App\Models\Payment::DUPLICATE_REASONS_PICKABLE. */
export type DuplicateReason = 'advance' | 'arrears' | 'refund' | 'other';
export type VerificationStatus = 'pending' | 'verified' | 'rejected';

export interface LocalCustomer {
    uuid: string;
    name: string;
    phone: string | null;
    bill: number;
    location: string | null;
    level: string | null;
    status: string;
    zone_uuid: string | null;
    cached_at: string;
    /** From the customer's latest manuscript, synced via pull() — see
     * SyncPullCustomer. `null` when the customer has no manuscript yet. */
    total_arrears: number | null;
    credit: number | null;
    /**
     * services.md section 6, added 2026-09-03. A JSON-encoded
     * `CustomerServiceApi[]` (SQLite has no native array/JSON column type)
     * — `null` for a row cached before this column existed. Parse with
     * `parseLocalCustomerServices()` (src/db/customers.ts) rather than
     * `JSON.parse` directly, which also handles that null/malformed case.
     */
    services: string | null;
    /**
     * Installation phase 2: JSON-encoded PromoState (src/utils/installation.ts)
     * — first billed month, FREE vs PREPAID, free months to come. Parse with
     * `parseLocalCustomerPromo()`. `null` for rows cached before the column.
     */
    promo?: string | null;
    /** The customer's branch — offers are sold at its price. `null` until the next pull refreshes the row. */
    branch_uuid?: string | null;
    /**
     * Old debt still unpaid as the server counted it at `cached_at` — every
     * collector's payments included. `null` from older servers / before the
     * next pull refreshes the row.
     */
    unpaid_debt?: number | null;
}

export interface LocalPayment {
    local_uuid: string;
    server_uuid: string | null;
    customer_uuid: string;
    amount: number;
    credit: number;
    frequency: 'monthly' | 'yearly' | 'months';
    months: number | null;
    /** Draw-down Q1 — stored 0/1 in SQLite. Only meaningful for months/yearly. */
    clear_arrears_first: boolean;
    verification_status: VerificationStatus;
    rejection_reason: string | null;
    /** Local file URI captured via the camera at submit time; uploaded
     * separately once `server_uuid` is known (offline-sync-strategy.md
     * §4.4) — see src/sync/SyncManager.ts's uploadPendingReceipts(). */
    receipt_local_uri: string | null;
    receipt_server_path: string | null;
    /** Cash controls: how the customer paid (cash / mtn_momo / orange_money / bank) + operator transaction ID. */
    method: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
    method_reference: string | null;
    /** The system-issued receipt this phone printed for the payment (src/fieldReceipts), or null. */
    receipt_number: string | null;
    security_code: string | null;
    receipt_issued_at: string | null;
    /** Why a second payment for the customer this month is fine, as the agent confirmed it (null when it isn't one). */
    duplicate_reason?: DuplicateReason | null;
    duplicate_note?: string | null;
    /** The prepayment offer sold (its months and price are fixed). */
    prepayment_offer_uuid?: string | null;
    sync_status: SyncStatus;
    sync_error: string | null;
    sync_attempts: number;
    created_at: string;
    updated_at: string;
}

/**
 * Outbox + history row of the `expenditures` table. Since
 * docs/plans/expenditures-and-spending-controls.md every row is a bon
 * request ("Request a bon"): `description` is what it is for, `spent_at`
 * the day the money is needed, `server_uuid` the bon's uuid once synced.
 * Rows queued by an older build (no payee) still sync — the server turns
 * them into pending bons too.
 */
export interface LocalExpenditure {
    local_uuid: string;
    server_uuid: string | null;
    category_uuid: string;
    amount: number;
    description: string | null;
    spent_at: string;
    payee_name?: string | null;
    /** 0/1 — already paid out of this agent's collections; settled at the cash handover. */
    from_collections?: number;
    voucher_number?: string | null;
    notes: string | null;
    receipt_local_uri: string | null;
    receipt_server_path: string | null;
    sync_status: SyncStatus;
    sync_error: string | null;
    sync_attempts: number;
    created_at: string;
    updated_at: string;
}

export interface LocalExpenseCategory {
    uuid: string;
    name: string;
    icon: string | null;
    is_active: number; // SQLite has no boolean; stored 0/1
    sort_order: number;
}

export type ComplaintCategory = 'operational' | 'customer';

/**
 * Outbox + local-history row for the "Log a Complaint" screen — mirrors
 * `expenditures`' shape exactly (complaint-desk.md section 7): create-only,
 * no edit/delete from mobile, `local_uuid` is the sync idempotency key
 * (App\Services\SyncService::pushComplaint()). No photo columns — the web
 * Complaint submission form itself deliberately ships photo attachment as
 * "coming in a follow-up update" (resources/tsx/pages/Complaints/Create.tsx),
 * so mobile mirrors that same disabled placeholder rather than building a
 * capture path with no backend counterpart yet. No `urgent` grading beyond
 * the plain boolean fast-path toggle — see complaint-desk.md section 6/7's
 * explicit "no self-declared priority" rule.
 */
export interface LocalComplaint {
    local_uuid: string;
    server_uuid: string | null;
    category: ComplaintCategory;
    title: string;
    description: string | null;
    urgent: number; // SQLite has no boolean; stored 0/1
    customer_uuid: string | null;
    sync_status: SyncStatus;
    sync_error: string | null;
    sync_attempts: number;
    created_at: string;
    updated_at: string;
}

export type NotificationSeverity = 'info' | 'warning' | 'urgent' | 'emergency';

/**
 * Local cache of this user's own notification feed, replaced/merged from
 * pull()'s `changes.notifications` block only (in-app-notifications.md
 * section 6) — never locally created, mirroring `customers`' read-only
 * cache shape. `ack_pending` is the one mobile-only addition: set when the
 * agent has pressed Acknowledge on the emergency interrupt screen while
 * offline (complaint-desk.md section 7's "queue and confirm once
 * connectivity returns, don't silently drop it") — cleared, and
 * `acknowledged_at` set from the server's own timestamp, only once
 * SyncManager's push cycle has confirmed the acknowledge round-tripped.
 * `read_at` is display-only on mobile (no local mark-read action — see
 * src/db/notifications.ts's doc comment); it only ever reflects state
 * synced down from the server (e.g. the same notification read on web).
 */
export interface LocalNotification {
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
    /** 1 while an acknowledge action is queued locally, waiting for
     * connectivity — see LocalNotification's class doc above. */
    ack_pending: number;
    cached_at: string;
}


/**
 * A customer registered in the field, offline (installation phase 3) — the
 * `installations` table doubles as outbox and history, keyed by the
 * registration's local_uuid. `payload` is the JSON-encoded sync item
 * (FieldInstallationItem); `activation` is the office's decision once
 * synced, from the pull.
 */
export interface LocalInstallation {
    local_uuid: string;
    customer_uuid: string;
    name: string;
    phone: string | null;
    collect_now: number;
    payload: string;
    sync_status: 'queued' | 'failed' | 'synced';
    sync_error: string | null;
    sync_attempts: number;
    activation: 'pending' | 'activated' | 'rejected' | null;
    decision_reason: string | null;
    possible_duplicate: number;
    created_at: string;
    updated_at: string;
}

/**
 * `cache_*` — what the offline add-customer form needs (installation phase
 * 3): zones, the service catalogue and the installation options, refreshed
 * on sync and whenever the form opens online. JSON-encoded API payloads.
 */
export type SyncMetaKey =
    | 'last_sync_at'
    | 'device_id'
    | 'agent_zone_uuid'
    | 'cache_zones'
    | 'cache_service_catalogue'
    | 'cache_installation_options'
    | 'cache_prepayment_offers';
