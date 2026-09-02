import { Linking } from 'react-native';
import { apiClient } from './client';
import type { PaymentReceiptResponse } from '../types/api';

/**
 * GET /api/v1/payments/{uuid}/receipt — the business-issued receipt for a
 * verified payment (Wave 2 of payment-receipts-and-whatsapp.md). Online-only:
 * a receipt is never part of the offline `payments` outbox cache, and the
 * signed `shared_pdf_url` it returns is minted fresh server-side.
 *
 * 404 (rejected promise) when the payment has no receipt yet — the caller
 * treats that as "not issued", not an error.
 *
 * Gated server-side by PaymentReceiptPolicy::view() (`payments.view`).
 */
export async function fetchPaymentReceipt(paymentUuid: string): Promise<PaymentReceiptResponse> {
    const { data } = await apiClient.get<PaymentReceiptResponse>(`/payments/${paymentUuid}/receipt`);

    return data;
}

/**
 * Opens the receipt PDF. This project's React Native app has no PDF viewer
 * or file-system libs (expo-file-system / expo-sharing are not installed —
 * see mobile-app-react-native.md), so "view" means handing the signed public
 * URL to the OS, which opens it in the device browser / a PDF handler. The
 * URL is the unauthenticated `shared_pdf_url` precisely because the system
 * browser can't attach the Sanctum token the authenticated `pdf_url` needs.
 */
export async function openReceiptPdf(sharedPdfUrl: string): Promise<void> {
    await Linking.openURL(sharedPdfUrl);
}
