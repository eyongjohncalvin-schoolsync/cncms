import { AppState, type AppStateStatus } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { acknowledgeNotification, fetchExpenseCategories, pullChanges, pushChanges, uploadReceipt } from '../api/sync';
import { isNetworkError, extractErrorMessage } from '../api/client';
import { upsertExpenseCategories } from '../db/categories';
import {
    getFailedPaymentsCount,
    getPaymentsAwaitingReceiptUpload,
    getQueuedPayments,
    getQueuedPaymentsCount,
    markPaymentFailed,
    markPaymentReceiptUploaded,
    markPaymentSynced,
    applyVerificationUpdates,
} from '../db/payments';
import {
    getFailedExpendituresCount,
    getQueuedExpenditures,
    getQueuedExpendituresCount,
    markExpenditureFailed,
    markExpenditureSynced,
} from '../db/expenditures';
import {
    getFailedComplaintsCount,
    getQueuedComplaints,
    getQueuedComplaintsCount,
    markComplaintFailed,
    markComplaintSynced,
} from '../db/complaints';
import { deleteCustomers, upsertCustomers } from '../db/customers';
import { getPendingAcknowledgements, markAckConfirmed, markAckPending, upsertNotifications } from '../db/notifications';
import { refreshNotificationsState } from '../notifications/notificationStore';
import { clearLastSyncAt, getLastSyncAt, getOrCreateDeviceId, setLastSyncAt } from '../db/syncMeta';
import { patchSyncState } from './syncStore';
import type { SyncPushComplaintItem, SyncPushExpenditureItem, SyncPushPaymentItem, SyncPullNotificationItem } from '../types/api';

/**
 * Speaks the server's real sync protocol directly (POST /sync/push, GET
 * /sync/pull) — deliberately not a general bidirectional-replication
 * engine, per mobile-app-react-native.md §2. Owns its own trigger wiring
 * (AppState, NetInfo, a periodic timer) and retry backoff so the React
 * layer only needs to call `syncManager.start()` once and
 * `syncManager.syncNow('manual')` for an explicit pull-to-refresh /
 * sync-strip tap.
 *
 * Explicitly NOT built: OS-level background sync (Background
 * Fetch/WorkManager) — out of v1 scope per the design doc. The durable
 * local outbox makes "sync eventually happens" correct regardless of which
 * of the four in-foreground triggers fires next.
 */

// 5s / 15s / 45s / 2m / 5m — offline-sync-strategy.md §6's backoff table,
// applied only to whole-sync-attempt network failures (not per-item
// validation failures, which are surfaced immediately and retried on the
// next natural trigger instead of a tight timer loop).
const RETRY_SCHEDULE_MS = [5_000, 15_000, 45_000, 120_000, 300_000];

// Within the design doc's "5-10 min" periodic in-foreground window.
const PERIODIC_INTERVAL_MS = 5 * 60 * 1000;

class SyncManagerImpl {
    private started = false;
    private inFlight = false;
    private isOnline = true;
    private retryAttempt = 0;
    private retryTimer: ReturnType<typeof setTimeout> | null = null;
    private periodicTimer: ReturnType<typeof setInterval> | null = null;
    private appStateSub: { remove: () => void } | null = null;
    private netInfoUnsub: (() => void) | null = null;
    private categoriesRefreshedThisSession = false;

    start(): void {
        if (this.started) {
            return;
        }

        this.started = true;

        void this.refreshCategoriesOnce();

        this.netInfoUnsub = NetInfo.addEventListener((state) => {
            const wasOffline = !this.isOnline;
            this.isOnline = state.isConnected === true;
            patchSyncState({ isOnline: this.isOnline });

            if (wasOffline && this.isOnline) {
                void this.syncNow('reconnect');
            }
        });

        this.appStateSub = AppState.addEventListener('change', (next: AppStateStatus) => {
            if (next === 'active') {
                void this.syncNow('foreground');
            }
        });

        this.periodicTimer = setInterval(() => {
            void this.syncNow('periodic');
        }, PERIODIC_INTERVAL_MS);

        void this.refreshQueuedCounts();
        void this.syncNow('startup');
    }

    stop(): void {
        this.appStateSub?.remove();
        this.netInfoUnsub?.();

        if (this.periodicTimer) {
            clearInterval(this.periodicTimer);
        }

        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
        }

        this.started = false;
    }

    /**
     * expense_categories comes from the plain GET /resources/categories
     * REST endpoint, NOT from pull() — confirmed SyncService::pull() only
     * returns customers/payments. Refreshed opportunistically once per
     * session per mobile-app-react-native.md §2, not on every sync tick.
     */
    private async refreshCategoriesOnce(): Promise<void> {
        if (this.categoriesRefreshedThisSession) {
            return;
        }

        try {
            const response = await fetchExpenseCategories();
            await upsertExpenseCategories(response.data);
            this.categoriesRefreshedThisSession = true;
        } catch {
            // Best-effort — a stale/empty local category list is a minor
            // inconvenience, not a correctness problem, and this will
            // retry on the next app start (or could be retried from a
            // future manual trigger without re-guarding the flag).
        }
    }

    /**
     * Fire-and-forget nudge implementing the design doc's "immediately
     * after each local write (if online)" trigger (§2) — call this right
     * after insertLocalPayment()/insertLocalExpenditure() so a just-queued
     * item gets a shot at syncing near-instantly instead of waiting for the
     * next foreground/periodic/reconnect trigger. This is what lets Record
     * Payment's confirmation badge legitimately flip from amber to green
     * within the same screen visit; see mobile-app-react-native.md §5 — the
     * badge must still start amber unconditionally, this only ever
     * accelerates a possible flip, never guarantees one. No-op while
     * offline; syncNow()'s own `inFlight` guard makes this safe to call
     * even if a sync happens to already be running.
     */
    notifyLocalWrite(): void {
        if (this.isOnline) {
            void this.syncNow('local-write');
        }
    }

    async refreshQueuedCounts(): Promise<void> {
        const [queuedPayments, queuedExpenditures, queuedComplaints, failedPayments, failedExpenditures, failedComplaints] =
            await Promise.all([
                getQueuedPaymentsCount(),
                getQueuedExpendituresCount(),
                getQueuedComplaintsCount(),
                getFailedPaymentsCount(),
                getFailedExpendituresCount(),
                getFailedComplaintsCount(),
            ]);

        patchSyncState({
            queuedCount: queuedPayments + queuedExpenditures + queuedComplaints,
            failedCount: failedPayments + failedExpenditures + failedComplaints,
        });
    }

    async syncNow(trigger: string): Promise<void> {
        if (this.inFlight) {
            return;
        }

        this.inFlight = true;

        try {
            await this.push();
            await this.pull();
            await this.uploadPendingReceipts();
            await this.flushPendingAcknowledgements();

            this.retryAttempt = 0;

            if (this.retryTimer) {
                clearTimeout(this.retryTimer);
                this.retryTimer = null;
            }

            patchSyncState({ lastError: null });
        } catch (error) {
            if (isNetworkError(error)) {
                // Expected/normal — no lastError surfaced, this is the
                // calm "offline, queuing" path, not an error state.
                this.scheduleRetry();
            } else {
                patchSyncState({ lastError: extractErrorMessage(error, `Sync failed (${trigger}).`) });
                this.scheduleRetry();
            }
        } finally {
            patchSyncState({ syncingProgress: null });
            await this.refreshQueuedCounts();
            this.inFlight = false;
        }
    }

    /**
     * Called directly by the emergency interrupt screen's Acknowledge
     * button (app/emergency.tsx) — attempts the real online action
     * immediately rather than waiting for the next sync tick, since an
     * emergency broadcast is exactly the case where "wait up to 5 minutes
     * for the periodic timer" is too slow. Falls back to queuing
     * (ack_pending=1) on any failure, network or otherwise; the regular
     * sync cycle's flushPendingAcknowledgements() retries it from there.
     * Never throws — the caller only needs to know whether it confirmed
     * immediately or was queued, to show the right confirmation copy.
     */
    async acknowledgeEmergency(uuid: string): Promise<'confirmed' | 'queued'> {
        try {
            const result = await acknowledgeNotification(uuid);
            await markAckConfirmed(uuid, result.acknowledged_at);
            await refreshNotificationsState();

            return 'confirmed';
        } catch {
            await markAckPending(uuid);
            await refreshNotificationsState();

            return 'queued';
        }
    }

    /**
     * Forces a full re-pull of every eligible customer, instead of the
     * normal delta pull scoped to `updated_at >= last_sync_at`. Exists
     * because that delta filter (SyncService::upsertedCustomers()) is blind
     * to any customer whose cached `total_arrears`/`credit` went stale
     * without the customer row's own `updated_at` changing — which is
     * exactly what happens when a manuscript is recalculated, corrected, or
     * (as in the 2026-08-28 incident) deleted directly against the
     * database: no `$touches` relationship exists from Manuscript back to
     * Customer, so none of that ever bumps `customers.updated_at`. A normal
     * "Sync Now" (syncNow('manual')) does NOT fix this — it still reads the
     * persisted `last_sync_at` watermark unchanged, so a customer whose own
     * row wasn't independently touched stays permanently excluded from
     * every future delta pull until something else touches it.
     *
     * This is the one thing that actually fixes it client-side: clear the
     * watermark so the next pull's `$sinceAt` is null, which makes
     * `upsertedCustomers()` return every zone-scoped customer unconditionally
     * (`->when($sinceAt, ...)` is simply skipped), re-populating this
     * device's cache with current server truth regardless of what did or
     * didn't touch `updated_at`. Safe to call anytime: `upsertCustomers()`
     * is a plain `ON CONFLICT DO UPDATE` upsert (src/db/customers.ts), never
     * a delete-then-insert, and a zone is only low hundreds of customers
     * (mobile-app-react-native.md §2), so this is a cheap, low-risk network
     * call, not a heavy resync. Queued/failed payments and expenditures are
     * untouched — only the customers-pull watermark is reset, push() and
     * everything else in this same syncNow() cycle behaves exactly as
     * normal.
     */
    async forceFullResync(): Promise<void> {
        await clearLastSyncAt();
        await this.syncNow('manual-full-refresh');
    }

    private scheduleRetry(): void {
        if (this.retryTimer || this.retryAttempt >= RETRY_SCHEDULE_MS.length) {
            return;
        }

        const delay = RETRY_SCHEDULE_MS[this.retryAttempt];
        this.retryAttempt += 1;

        this.retryTimer = setTimeout(() => {
            this.retryTimer = null;
            void this.syncNow('retry');
        }, delay);
    }

    private async push(): Promise<void> {
        const [queuedPayments, queuedExpenditures, queuedComplaints] = await Promise.all([
            getQueuedPayments(),
            getQueuedExpenditures(),
            getQueuedComplaints(),
        ]);

        if (queuedPayments.length === 0 && queuedExpenditures.length === 0 && queuedComplaints.length === 0) {
            return;
        }

        patchSyncState({
            syncingProgress: { done: 0, total: queuedPayments.length + queuedExpenditures.length + queuedComplaints.length },
        });

        const deviceId = await getOrCreateDeviceId();
        const lastSyncAt = await getLastSyncAt();

        const paymentItems: SyncPushPaymentItem[] = queuedPayments.map((payment) => ({
            local_uuid: payment.local_uuid,
            customer_uuid: payment.customer_uuid,
            amount: payment.amount,
            credit: payment.credit,
            frequency: payment.frequency,
            months: payment.months,
            // SQLite stores this as 0/1; coerce for the JSON payload.
            clear_arrears_first: !!payment.clear_arrears_first,
            created_at: payment.created_at,
        }));

        const expenditureItems: SyncPushExpenditureItem[] = queuedExpenditures.map((expenditure) => ({
            local_uuid: expenditure.local_uuid,
            category_uuid: expenditure.category_uuid,
            amount: expenditure.amount,
            description: expenditure.description,
            spent_at: expenditure.spent_at,
            notes: expenditure.notes,
        }));

        // complaint-desk.md section 7 — third create-only sync entity type,
        // exact same push shape as payments/expenditures above.
        const complaintItems: SyncPushComplaintItem[] = queuedComplaints.map((complaint) => ({
            local_uuid: complaint.local_uuid,
            category: complaint.category,
            title: complaint.title,
            description: complaint.description ?? '',
            urgent: complaint.urgent === 1,
            customer_uuid: complaint.customer_uuid,
            created_at: complaint.created_at,
        }));

        const response = await pushChanges({
            device_id: deviceId,
            last_sync_at: lastSyncAt,
            changes: { payments: paymentItems, expenditures: expenditureItems, complaints: complaintItems },
        });

        await Promise.all([
            ...response.results.payments.map((result) =>
                result.status === 'synced' && result.server_uuid
                    ? markPaymentSynced(result.local_uuid, result.server_uuid)
                    : markPaymentFailed(result.local_uuid, result.error ?? 'Sync failed'),
            ),
            ...response.results.expenditures.map((result) =>
                result.status === 'synced' && result.server_uuid
                    ? markExpenditureSynced(result.local_uuid, result.server_uuid)
                    : markExpenditureFailed(result.local_uuid, result.error ?? 'Sync failed'),
            ),
            ...response.results.complaints.map((result) =>
                result.status === 'synced' && result.server_uuid
                    ? markComplaintSynced(result.local_uuid, result.server_uuid)
                    : markComplaintFailed(result.local_uuid, result.error ?? 'Sync failed'),
            ),
        ]);

        patchSyncState({
            syncingProgress: {
                done: response.results.payments.length + response.results.expenditures.length + response.results.complaints.length,
                total: queuedPayments.length + queuedExpenditures.length + queuedComplaints.length,
            },
        });
    }

    private async pull(): Promise<void> {
        const since = await getLastSyncAt();
        const response = await pullChanges(since);

        await upsertCustomers(response.changes.customers.upserted);
        await deleteCustomers(response.changes.customers.deleted);
        await applyVerificationUpdates(response.changes.payments.verified, 'verified');
        await applyVerificationUpdates(response.changes.payments.rejected, 'rejected');

        // in-app-notifications.md section 6 / complaint-desk.md section 7 —
        // `items` (this user's recent feed) and `emergency` (every
        // currently-unacknowledged emergency, not bounded by the same
        // limit as `items`) can overlap; dedupe by uuid before writing so
        // upsertNotifications() never runs the same row twice in one pull.
        const notificationsByUuid = new Map<string, SyncPullNotificationItem>();
        for (const item of [...response.changes.notifications.items, ...response.changes.notifications.emergency]) {
            notificationsByUuid.set(item.uuid, item);
        }
        await upsertNotifications(Array.from(notificationsByUuid.values()));

        await setLastSyncAt(response.synced_at);

        patchSyncState({ lastSyncAt: response.synced_at });
        await refreshNotificationsState();
    }

    /**
     * Retries every locally-queued Acknowledge action (complaint-desk.md
     * section 7: "if offline when the agent tries to acknowledge, queue it
     * and confirm once connectivity returns, don't silently drop it").
     * Runs after push()/pull() in the same sync cycle, mirroring
     * uploadPendingReceipts()'s placement and per-item best-effort
     * try/catch — one failed acknowledge retry must never fail the whole
     * sync attempt or block the others.
     */
    private async flushPendingAcknowledgements(): Promise<void> {
        const pending = await getPendingAcknowledgements();

        if (pending.length === 0) {
            return;
        }

        let anyConfirmed = false;

        for (const notification of pending) {
            try {
                const result = await acknowledgeNotification(notification.uuid);
                await markAckConfirmed(notification.uuid, result.acknowledged_at);
                anyConfirmed = true;
            } catch {
                // Swallowed deliberately — still offline, or a transient
                // server error. Stays ack_pending=1 and is retried on the
                // next sync cycle; the persistent banner keeps reflecting
                // "queued" until this succeeds.
            }
        }

        if (anyConfirmed) {
            await refreshNotificationsState();
        }
    }

    /**
     * Uploads receipt photos for payments that have already synced (real
     * server_uuid) but whose captured local photo hasn't been pushed yet —
     * per offline-sync-strategy.md §4.4, this is always a separate
     * multipart request addressed by entity_type+entity_uuid, never bundled
     * into push()'s payload. Runs after push()/pull() so a payment that
     * just synced THIS cycle is picked up in the same cycle rather than
     * waiting for the next trigger.
     *
     * Best-effort and per-item: one failed upload (still-offline receipt,
     * a transient 500) must never fail the whole sync attempt or block
     * other items — the payment itself is already safely synced either
     * way, so a stuck receipt is an inconvenience, not lost cash. Left
     * with receipt_server_path still NULL on failure so the next cycle
     * retries it automatically.
     */
    private async uploadPendingReceipts(): Promise<void> {
        const pending = await getPaymentsAwaitingReceiptUpload();

        for (const payment of pending) {
            if (!payment.server_uuid || !payment.receipt_local_uri) {
                continue;
            }

            try {
                const result = await uploadReceipt('payment', payment.server_uuid, payment.receipt_local_uri);
                await markPaymentReceiptUploaded(payment.local_uuid, result.receipt_url);
            } catch {
                // Swallowed deliberately — see doc comment above.
            }
        }
    }
}

export const syncManager = new SyncManagerImpl();
