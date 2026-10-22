import type { PaymentPurpose } from '@/types';

/** Display labels for Payment::PURPOSES (docs/plans/installation-and-promotions.md). */
export const PAYMENT_PURPOSE_LABELS: Record<PaymentPurpose, string> = {
    subscription: 'Subscription',
    installation: 'Installation',
    technician_fee: 'Technician fee',
};

/** A payment without a purpose (older payload) is a subscription payment. */
export function paymentPurposeLabel(purpose?: PaymentPurpose | null): string {
    return PAYMENT_PURPOSE_LABELS[purpose ?? 'subscription'];
}
