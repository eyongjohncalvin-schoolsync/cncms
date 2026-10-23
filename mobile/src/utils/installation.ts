/**
 * Pure, dependency-free logic for the "Installation & first payment" section
 * of Add Customer and for the promo state the sync pull carries
 * (docs/plans/installation-and-promotions.md, phase 2) — kept apart from the
 * screens so plain Node tests can exercise it. The server
 * (App\Services\CustomerInstallationService) stays the source of truth for
 * every amount; this only shapes what the form sends and what the screens
 * say.
 */

export interface OfferMonths {
    service_uuid: string;
    months: number;
}

export interface InstallationOfferLike {
    uuid: string;
    fee: string;
    months: OfferMonths[];
}

export interface InstallationFormState {
    connectedOn: string;
    offerUuid: string | null;
    /** '' = the offer's fee. */
    fee: string;
    /** '' = nothing paid now. */
    paidNow: string;
    /** null = the offer's months. */
    freeMonths: OfferMonths[] | null;
    advanceMonths: number;
    reason: string;
    method: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
    methodReference: string;
}

/** The fields Add Customer adds to POST /customers and /customers/installation-preview. */
export interface InstallationRequestFields {
    connected_on: string | null;
    installation_offer_uuid: string | null;
    installation_fee: string | null;
    installation_paid: string | null;
    installation_reason: string | null;
    free_months: OfferMonths[] | null;
    advance_months: number;
    method: InstallationFormState['method'];
    method_reference: string | null;
}

export function initialInstallationState(today: string, defaultOfferUuid: string | null): InstallationFormState {
    return {
        connectedOn: today,
        offerUuid: defaultOfferUuid,
        fee: '',
        paidNow: '',
        freeMonths: null,
        advanceMonths: 0,
        reason: '',
        method: 'cash',
        methodReference: '',
    };
}

/** Free months shown for a ticked service: the edited value, else the offer's, else 0. */
export function freeMonthsFor(offer: InstallationOfferLike | null, edited: OfferMonths[] | null, serviceUuid: string): number {
    const row = edited?.find((m) => m.service_uuid === serviceUuid);

    if (row) {
        return row.months;
    }

    return offer?.months.find((m) => m.service_uuid === serviceUuid)?.months ?? 0;
}

/**
 * Sets one service's free months, materialising the offer's months for the
 * other ticked services so the server sees the whole picture.
 */
export function withFreeMonths(
    offer: InstallationOfferLike | null,
    edited: OfferMonths[] | null,
    tickedServiceUuids: string[],
    serviceUuid: string,
    months: number,
): OfferMonths[] {
    const clamped = Number.isFinite(months) && months > 0 ? Math.floor(months) : 0;

    return tickedServiceUuids.map((uuid) => ({
        service_uuid: uuid,
        months: uuid === serviceUuid ? clamped : freeMonthsFor(offer, edited, uuid),
    }));
}

function blankToNull(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

export function toRequestFields(state: InstallationFormState): InstallationRequestFields {
    const advance = Number.isFinite(state.advanceMonths) && state.advanceMonths > 0 ? Math.floor(state.advanceMonths) : 0;

    return {
        connected_on: blankToNull(state.connectedOn),
        installation_offer_uuid: state.offerUuid,
        installation_fee: blankToNull(state.fee),
        installation_paid: blankToNull(state.paidNow),
        installation_reason: blankToNull(state.reason),
        free_months: state.freeMonths,
        advance_months: advance,
        method: state.method,
        method_reference: blankToNull(state.methodReference),
    };
}

/** Whether this save takes money (installation paid now or an advance). */
export function takesMoney(state: InstallationFormState): boolean {
    return Number(state.paidNow) > 0 || state.advanceMonths > 0;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2026-11" → "Nov 2026"; anything else is returned as-is. */
export function formatPeriod(period: string): string {
    const match = /^(\d{4})-(\d{2})$/.exec(period);

    if (!match) {
        return period;
    }

    const month = Number(match[2]);

    return month >= 1 && month <= 12 ? `${MONTHS[month - 1]} ${match[1]}` : period;
}

export interface PromoState {
    billing_starts_period?: string | null;
    is_free?: boolean;
    prepaid_months_remaining?: number;
    free_months?: { service: string; from: string; until: string }[];
}

/**
 * The offline customer screens' line: "FREE · TV free until Dec 2026",
 * "PREPAID · 3 months left", "First bill Nov 2026" — FREE and PREPAID are
 * kept apart (a free month is not money the customer paid). Null when
 * there is nothing to say.
 */
export function promoLabel(promo: PromoState | null | undefined, currentPeriod: string): string | null {
    if (!promo) {
        return null;
    }

    const parts: string[] = [];

    if (promo.is_free) {
        parts.push('FREE');
    } else if ((promo.prepaid_months_remaining ?? 0) > 0) {
        parts.push(`PREPAID · ${promo.prepaid_months_remaining} month${promo.prepaid_months_remaining === 1 ? '' : 's'} left`);
    }

    for (const window of promo.free_months ?? []) {
        if (window.until >= currentPeriod) {
            parts.push(`${window.service} free until ${formatPeriod(window.until)}`);
        }
    }

    if (promo.billing_starts_period && promo.billing_starts_period > currentPeriod) {
        parts.push(`First billed month ${formatPeriod(promo.billing_starts_period)}`);
    }

    return parts.length === 0 ? null : parts.join(' · ');
}
