import { apiClient } from './client';
import type {
    AcknowledgeNotificationResponse,
    ExpenseCategoryListResponse,
    ReceiptEntityType,
    SyncPullResponse,
    SyncPushRequestBody,
    SyncPushResponse,
    SyncStatusResponse,
    UploadReceiptResponse,
} from '../types/api';

export async function pushChanges(body: SyncPushRequestBody): Promise<SyncPushResponse> {
    const { data } = await apiClient.post<SyncPushResponse>('/sync/push', body);

    return data;
}

export async function pullChanges(since: string | null): Promise<SyncPullResponse> {
    const { data } = await apiClient.get<SyncPullResponse>('/sync/pull', {
        params: since ? { since } : undefined,
    });

    return data;
}

export async function fetchSyncStatus(deviceId: string): Promise<SyncStatusResponse> {
    const { data } = await apiClient.get<SyncStatusResponse>('/sync/status', {
        params: { device_id: deviceId },
    });

    return data;
}

/**
 * Not part of pull() — confirmed via SyncService::pull() that only
 * customers/payments come back from the sync endpoint. Refreshed
 * opportunistically once per session per mobile-app-react-native.md §2.
 */
export async function fetchExpenseCategories(): Promise<ExpenseCategoryListResponse> {
    const { data } = await apiClient.get<ExpenseCategoryListResponse>('/resources/categories');

    return data;
}

/**
 * POST /sync/upload-receipt — a separate multipart request sent only once
 * the owning record has synced and has a real `entityUuid` (server_uuid),
 * per offline-sync-strategy.md §4.4. `localUri` is the on-device camera
 * file URI captured at submit time (expo-image-picker/expo-camera), never
 * uploaded eagerly alongside the payment/expenditure itself.
 */
export async function uploadReceipt(
    entityType: ReceiptEntityType,
    entityUuid: string,
    localUri: string,
): Promise<UploadReceiptResponse> {
    const formData = new FormData();
    formData.append('entity_type', entityType);
    formData.append('entity_uuid', entityUuid);
    // React Native's FormData accepts this { uri, name, type } shape in
    // place of a Blob/File — the DOM FormData typings don't model it, hence
    // the cast.
    formData.append(
        'receipt',
        {
            uri: localUri,
            name: 'receipt.jpg',
            type: 'image/jpeg',
        } as unknown as Blob,
    );

    const { data } = await apiClient.post<UploadReceiptResponse>('/sync/upload-receipt', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return data;
}

/**
 * POST /api/v1/notifications/{uuid}/acknowledge — the emergency interrupt
 * screen's Acknowledge button (complaint-desk.md section 7). A real online
 * action, deliberately NOT part of pushChanges()'s outbox batch: it acts
 * on an existing server-side notification rather than creating a new
 * record, so it's called directly here and retried by SyncManager's own
 * pending-acknowledgements sweep (src/db/notifications.ts's
 * getPendingAcknowledgements()) when it fails offline, rather than being
 * folded into the payments/expenditures/complaints push batch shape.
 */
export async function acknowledgeNotification(uuid: string): Promise<AcknowledgeNotificationResponse> {
    const { data } = await apiClient.post<AcknowledgeNotificationResponse>(`/notifications/${uuid}/acknowledge`);

    return data;
}
