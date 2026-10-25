import type { DuplicateReason } from '../types/db';

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

/** Whether the chosen reason (and note, for "other") is enough to save. */
export function isDuplicateConfirmationComplete(reason: DuplicateReason | null, note: string): boolean {
    return reason !== null && (reason !== 'other' || note.trim() !== '');
}
