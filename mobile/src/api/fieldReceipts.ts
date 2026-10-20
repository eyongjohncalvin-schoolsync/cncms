import { apiClient } from './client';

/**
 * Cash controls (docs/plans/cash-controls.md) — App\Http\Controllers\Api\
 * FieldReceiptController. Plain HTTP calls; src/fieldReceipts/receiptStock.ts
 * decides when to make them.
 */

export interface RegisterFieldDeviceResponse {
    data: {
        device_uuid: string;
        name: string;
        /** Shown exactly once — kept in the secure store, never sent back. */
        secret: string;
        check_url_base: string;
    };
}

export interface ReceiptStockResponse {
    data: {
        device_uuid: string;
        numbers: string[];
        check_url_base: string;
    };
}

export async function registerFieldDevice(name: string): Promise<RegisterFieldDeviceResponse['data']> {
    const { data } = await apiClient.post<RegisterFieldDeviceResponse>('/field-devices', { name });

    return data.data;
}

export async function fetchReceiptStock(deviceUuid: string): Promise<ReceiptStockResponse['data']> {
    const { data } = await apiClient.get<ReceiptStockResponse>('/receipt-stock', {
        headers: { 'X-Field-Device': deviceUuid },
    });

    return data.data;
}

export async function cancelReceiptNumber(number: string, reason: string): Promise<void> {
    await apiClient.post(`/receipt-numbers/${encodeURIComponent(number)}/cancel`, { reason });
}
