/**
 * Pure, dependency-free logic for the Record Payment screen
 * (mobile-app-react-native.md §4) — kept separate from the screen component
 * so it can be exercised by a plain Node test script without pulling in
 * React Native/Expo modules. See scripts/test-record-payment.ts.
 */

export type PaymentFrequency = 'monthly' | 'yearly' | 'months';

/**
 * The non-binding "per-frequency calculation guide" — mirrors the web
 * app's Payments/Create.tsx `guideAmount` memo exactly (bill × 1 for
 * monthly, bill × N for multi-month, bill × 12 for yearly).
 *
 * PURELY INFORMATIONAL. Callers must NEVER use this return value to
 * auto-fill the amount field — see mobile-app-react-native.md §4's "Never
 * auto-fills the amount input" requirement, which applies at least as
 * strongly on mobile as web (arguably more, given real cash handling in
 * the field). Returns `null` when there isn't enough information yet to
 * suggest a figure (e.g. 'months' selected but no valid month count typed).
 */
export function calculateGuideAmount(bill: number, frequency: PaymentFrequency, months: number | null): number | null {
    if (!Number.isFinite(bill) || bill < 0) {
        return null;
    }

    if (frequency === 'monthly') {
        return bill;
    }

    if (frequency === 'yearly') {
        return bill * 12;
    }

    if (months === null || !Number.isFinite(months) || months <= 0) {
        return null;
    }

    return bill * months;
}

export interface PaymentFormValidationInput {
    customerUuid: string | null;
    /** Raw text-field value, not yet parsed. */
    amount: string;
    frequency: PaymentFrequency;
    /** Raw text-field value, only meaningful when frequency === 'months'. */
    months: string;
}

export interface PaymentFormValidationResult {
    valid: boolean;
    amountValue: number | null;
    monthsValue: number | null;
    errors: {
        customer?: string;
        amount?: string;
        months?: string;
    };
}

/**
 * Mirrors App\Http\Requests\StorePaymentRequest's real validation exactly:
 * customer_uuid required, amount required numeric gt 0, frequency required
 * in [monthly,yearly,months], months required_if frequency=months (integer,
 * min 1). Deliberately does NOT invent a stricter mobile-only requirement —
 * per mobile-app-react-native.md §4, "matches StorePaymentRequest's actual
 * validation, no mobile-only stricter requirement invented." Credit and
 * receipt photo are correctly absent here: both are optional server-side.
 */
export function validatePaymentForm(input: PaymentFormValidationInput): PaymentFormValidationResult {
    const errors: PaymentFormValidationResult['errors'] = {};

    if (!input.customerUuid) {
        errors.customer = 'Select a customer first.';
    }

    const trimmedAmount = input.amount.trim();
    const amountValue = trimmedAmount === '' ? NaN : Number(trimmedAmount);

    if (!Number.isFinite(amountValue) || amountValue <= 0) {
        errors.amount = 'Enter an amount greater than 0.';
    }

    let monthsValue: number | null = null;

    if (input.frequency === 'months') {
        const trimmedMonths = input.months.trim();
        const parsedMonths = trimmedMonths === '' ? NaN : Number(trimmedMonths);

        if (!Number.isInteger(parsedMonths) || parsedMonths < 1) {
            errors.months = 'Enter a whole number of months (1 or more).';
        } else {
            monthsValue = parsedMonths;
        }
    }

    return {
        valid: Object.keys(errors).length === 0,
        amountValue: Number.isFinite(amountValue) ? amountValue : null,
        monthsValue,
        errors,
    };
}
