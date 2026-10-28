import type { PaidThisMonthApi, PaymentCheckApi } from '../types/api';
import type { DuplicateReason, LocalCustomer, LocalPayment } from '../types/db';

/**
 * A second subscription payment for the same customer in the same month may
 * be a double entry (App\Services\PaymentService::flagPossibleDuplicate()).
 * The phone asks the agent why another payment is fine before saving it; the
 * server flags it and leaves it for the office either way.
 */

/** Business time is West Africa Time (UTC+1, no daylight saving) — as App\Support\BusinessTimezone. */
const WAT_OFFSET_MS = 60 * 60 * 1000;

/** The first instant of the current business month, as an ISO string (UTC) — comparable with payments.created_at. */
export function startOfBusinessMonthIso(now: Date): string {
    const wat = new Date(now.getTime() + WAT_OFFSET_MS);

    return new Date(Date.UTC(wat.getUTCFullYear(), wat.getUTCMonth(), 1) - WAT_OFFSET_MS).toISOString();
}

/** Mirrors App\Models\Payment::DUPLICATE_REASONS_PICKABLE (labels as on the web). */
export const DUPLICATE_REASONS: { value: DuplicateReason; label: string }[] = [
    { value: 'advance', label: 'Paying next month(s) in advance' },
    { value: 'arrears', label: 'Paying old debt' },
    { value: 'refund', label: "Customer's mistake — will be refunded" },
    { value: 'other', label: 'Other' },
];

/**
 * The same amount, same customer, recorded on this phone within this long:
 * the agent is re-entering a payment they think didn't go — refused, not
 * asked about (owner, 2026-10-28).
 */
export const REPEAT_BLOCK_MS = 30 * 60 * 1000;

/**
 * An earlier payment a new one may repeat, from wherever the phone learned
 * of it: the server (with signal — every collector's), this phone's own
 * payments, or the customer's last sync (offline — anyone's, as of then).
 */
export interface EarlierPayment {
    amount: number;
    at: string;
    recordedBy: string | null;
    receiptNumber: string | null;
    source: 'server' | 'phone' | 'last-sync';
}

export function earlierFromServer(earlier: NonNullable<PaymentCheckApi['earlier']>): EarlierPayment {
    return {
        amount: Number(earlier.amount),
        at: earlier.collected_at,
        recordedBy: earlier.recorded_by_me ? 'you' : earlier.recorded_by,
        receiptNumber: earlier.receipt_number,
        source: 'server',
    };
}

export function earlierFromPhone(payment: LocalPayment): EarlierPayment {
    return { amount: payment.amount, at: payment.created_at, recordedBy: 'you', receiptNumber: payment.receipt_number ?? null, source: 'phone' };
}

/** The latest payment this month the last sync brought — only if it is still this month. */
export function earlierFromLastSync(customer: Pick<LocalCustomer, 'paid_this_month'>, now: Date = new Date()): EarlierPayment | null {
    if (!customer.paid_this_month) {
        return null;
    }

    let paid: PaidThisMonthApi;

    try {
        paid = JSON.parse(customer.paid_this_month) as PaidThisMonthApi;
    } catch {
        return null;
    }

    if (paid.collected_at < startOfBusinessMonthIso(now)) {
        return null;
    }

    return { amount: Number(paid.amount), at: paid.collected_at, recordedBy: paid.recorded_by, receiptNumber: null, source: 'last-sync' };
}

/** Whether the chosen reason (and note, for "other") is enough to save. */
export function isDuplicateConfirmationComplete(reason: DuplicateReason | null, note: string): boolean {
    return reason !== null && (reason !== 'other' || note.trim() !== '');
}
