/**
 * Pure, dependency-free logic for the "Installation & first payment" section
 * of Add Customer and for the promo state the sync pull carries
 * (docs/plans/installation-and-promotions.md, phase 2 + revision D15–D19) —
 * kept apart from the screens so plain Node tests can exercise it. The
 * server (App\Services\CustomerInstallationService) stays the source of
 * truth for every amount; this only shapes what the form sends and what the
 * screens say.
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

/** One ticked service's choice (D15): free months from a month, then months paid upfront. */
export interface ServicePlan {
    service_uuid: string;
    free_from: string | null;
    free_months: number;
    paid_months: number;
}

export interface InstallationFormState {
    connectedOn: string;
    offerUuid: string | null;
    /** '' = the offer's fee. */
    fee: string;
    /** '' = nothing paid now. */
    paidNow: string;
    servicePlans: ServicePlan[];
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
    service_plans: ServicePlan[];
    method: InstallationFormState['method'];
    method_reference: string | null;
}

export function initialInstallationState(today: string, defaultOfferUuid: string | null): InstallationFormState {
    return {
        connectedOn: today,
        offerUuid: defaultOfferUuid,
        fee: '',
        paidNow: '',
        servicePlans: [],
        reason: '',
        method: 'cash',
        methodReference: '',
    };
}

/** "2026-10" + n months → "YYYY-MM". */
export function addMonths(period: string, n: number): string {
    const [y, m] = period.split('-').map(Number);
    const total = y * 12 + (m - 1) + n;

    return `${Math.floor(total / 12)}-${String((total % 12) + 1).padStart(2, '0')}`;
}

function monthIndex(from: string, period: string): number {
    const [fy, fm] = from.split('-').map(Number);
    const [y, m] = period.split('-').map(Number);

    return (y - fy) * 12 + (m - fm);
}

/**
 * The connection month: free months count from here, even when it has
 * closed (a late registration) — App\Support\Billing\ConnectionMonth.
 */
export function installationMonth(connectedOn: string, today: string): string {
    return (connectedOn || today).slice(0, 7);
}

/** Whole months from `from` to `period` (`YYYY-MM`), negative when earlier. */
export function monthsBetween(from: string, period: string): number {
    return monthIndex(from, period);
}

/** Registered after the month they were connected in had passed. */
export function isLateRegistration(connectedOn: string, today: string): boolean {
    return installationMonth(connectedOn, today) < today.slice(0, 7);
}

/** A newly ticked service starts with the offer's months, else the installation month free (D16). */
export function defaultPlan(serviceUuid: string, firstMonth: string, offer: InstallationOfferLike | null): ServicePlan {
    const preset = offer?.months.find((m) => m.service_uuid === serviceUuid)?.months;
    const months = offer ? (preset ?? 0) : 1;

    return { service_uuid: serviceUuid, free_from: months > 0 ? firstMonth : null, free_months: months, paid_months: 0 };
}

/** One plan per ticked service, keeping what was already chosen. */
export function syncPlans(plans: ServicePlan[], tickedServiceUuids: string[], firstMonth: string, offer: InstallationOfferLike | null): ServicePlan[] {
    return tickedServiceUuids.map((uuid) => plans.find((p) => p.service_uuid === uuid) ?? defaultPlan(uuid, firstMonth, offer));
}

/**
 * Tapping a month chip keeps the free months one consecutive run (D16):
 * extend the run to the month, cut it there, or start it there.
 */
export function toggleFreeMonth(plan: ServicePlan, month: string, firstMonth: string): ServicePlan {
    const start = plan.free_from ?? firstMonth;

    if (plan.free_months === 0) {
        return { ...plan, free_from: month, free_months: 1 };
    }

    const offset = monthIndex(start, month);

    if (offset < 0) {
        return { ...plan, free_from: month, free_months: plan.free_months - offset };
    }

    if (offset >= plan.free_months) {
        return { ...plan, free_months: offset + 1 };
    }

    if (offset === 0) {
        return plan.free_months === 1 ? { ...plan, free_from: null, free_months: 0 } : { ...plan, free_from: addMonths(start, 1), free_months: plan.free_months - 1 };
    }

    return { ...plan, free_months: offset };
}

/** Whether month is one of the plan's free months. */
export function isFreeMonth(plan: ServicePlan, month: string): boolean {
    if (plan.free_months === 0 || plan.free_from === null) {
        return false;
    }

    const offset = monthIndex(plan.free_from, month);

    return offset >= 0 && offset < plan.free_months;
}

function blankToNull(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

export function toRequestFields(state: InstallationFormState): InstallationRequestFields {
    return {
        connected_on: blankToNull(state.connectedOn),
        installation_offer_uuid: state.offerUuid,
        installation_fee: blankToNull(state.fee),
        installation_paid: blankToNull(state.paidNow),
        installation_reason: blankToNull(state.reason),
        service_plans: state.servicePlans.map((p) => ({
            ...p,
            free_months: Math.max(0, Math.floor(p.free_months) || 0),
            paid_months: Math.max(0, Math.floor(p.paid_months) || 0),
        })),
        method: state.method,
        method_reference: blankToNull(state.methodReference),
    };
}

/** Whether this save takes money (installation paid now or months paid upfront). */
export function takesMoney(state: InstallationFormState): boolean {
    return Number(state.paidNow) > 0 || state.servicePlans.some((p) => p.paid_months > 0);
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
    /** 'pending' = registered in the field, not activated by the office yet (phase 3). */
    activation_status?: 'pending' | null;
    billing_starts_period?: string | null;
    is_free?: boolean;
    prepaid_months_remaining?: number;
    service_paid_months?: { service: string; months: number }[];
    free_months?: { service: string; from: string; until: string }[];
}

/**
 * The offline customer screens' line: "FREE · TV free until Dec 2026",
 * "PREPAID · 3 months left", "TV: 2 months paid upfront left" — FREE and
 * PREPAID are kept apart (a free month is not money the customer paid).
 * Null when there is nothing to say.
 */
export function promoLabel(promo: PromoState | null | undefined, currentPeriod: string): string | null {
    if (!promo) {
        return null;
    }

    // A field registration is not billed until the office activates it.
    if (promo.activation_status === 'pending') {
        return 'PENDING ACTIVATION · not billed until the office activates';
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

    for (const paid of promo.service_paid_months ?? []) {
        if (paid.months > 0) {
            parts.push(`${paid.service}: ${paid.months} month${paid.months === 1 ? '' : 's'} paid upfront left`);
        }
    }

    return parts.length === 0 ? null : parts.join(' · ');
}
