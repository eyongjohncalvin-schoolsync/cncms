import { apiClient } from './client';
import type { BillHistoryResponse, BillWhatsappMessageResponse } from '../types/api';

/**
 * GET /api/v1/bills/{uuid}/whatsapp-message — the pre-formatted bill
 * message + normalized phone number for the "Send Bill via WhatsApp"
 * action on Customer Detail (mobile-app-react-native.md, bill-
 * notifications.md §1). Always online-only: this composes fresh from the
 * server's ManuscriptService/BillNotificationService data (same source the
 * printed bill and the web Manuscripts page's wa_link column use), not
 * something cached locally — there is no offline fallback for it, same
 * reasoning as fetchCustomerDetail() in src/api/customers.ts.
 *
 * Gated server-side by CustomerPolicy::printBill() (super/admin/manager/
 * agent) — the same role set as bill-print access.
 */
export async function fetchBillWhatsappMessage(uuid: string): Promise<BillWhatsappMessageResponse> {
    const { data } = await apiClient.get<BillWhatsappMessageResponse>(`/bills/${uuid}/whatsapp-message`);

    return data;
}

/**
 * GET /api/v1/bills/{uuid}/history — the customer's last 12 monthly bills
 * with their bill number and paid status, plus signed statement-of-account
 * links (opened in the device browser, like receipt PDFs). Online-only.
 * Gated by `customers.view`; the statement links only for
 * `customers.print_bill`.
 */
export async function fetchBillHistory(uuid: string): Promise<BillHistoryResponse> {
    const { data } = await apiClient.get<BillHistoryResponse>(`/bills/${uuid}/history`);

    return data;
}
