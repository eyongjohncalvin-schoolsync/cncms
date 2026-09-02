/**
 * Local SQLite row shapes — see mobile-app-react-native.md §2 for the
 * schema rationale (payments/expenditures double as outbox + history,
 * customers is a read-only cache, expense_categories is refreshed from the
 * plain REST endpoint not from pull()).
 */

export type SyncStatus = 'queued' | 'syncing' | 'synced' | 'failed';
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
    sync_status: SyncStatus;
    sync_error: string | null;
    sync_attempts: number;
    created_at: string;
    updated_at: string;
}

export interface LocalExpenditure {
    local_uuid: string;
    server_uuid: string | null;
    category_uuid: string;
    amount: number;
    description: string | null;
    spent_at: string;
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

export type SyncMetaKey = 'last_sync_at' | 'device_id' | 'agent_zone_uuid';
