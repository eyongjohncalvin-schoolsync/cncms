import type { VerificationStatus } from '../types/db';

/**
 * Filter-chip logic for the History ("My Recorded Payments") screen — kept
 * as a pure function, separate from the screen component, so it's directly
 * unit-testable (see src/utils/__tests__/paymentFilters.test.ts).
 */
export type PaymentStatusFilter = 'all' | VerificationStatus;

export const PAYMENT_STATUS_FILTERS: PaymentStatusFilter[] = ['all', 'pending', 'verified', 'rejected'];

export function filterLabel(filter: PaymentStatusFilter): string {
    switch (filter) {
        case 'all':
            return 'All';
        case 'pending':
            return 'Pending';
        case 'verified':
            return 'Verified';
        case 'rejected':
            return 'Rejected';
        default:
            return filter;
    }
}

export function filterPaymentsByStatus<T extends { verification_status: VerificationStatus }>(
    payments: T[],
    filter: PaymentStatusFilter,
): T[] {
    if (filter === 'all') {
        return payments;
    }

    return payments.filter((payment) => payment.verification_status === filter);
}

/** "Monthly" / "Yearly" / "3 months" per payments.frequency + months. */
export function formatFrequency(frequency: 'monthly' | 'yearly' | 'months', months: number | null): string {
    if (frequency === 'monthly') {
        return 'Monthly';
    }

    if (frequency === 'yearly') {
        return 'Yearly';
    }

    if (months && months > 0) {
        return `${months} month${months === 1 ? '' : 's'}`;
    }

    return 'Multi-month';
}
