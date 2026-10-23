/**
 * Pure, dependency-free logic for registering a customer in the field,
 * offline (docs/plans/installation-and-promotions.md, phase 3, D10): the
 * amounts the phone signs receipts for, the sync item it queues, and the
 * labels the sync status / history screens show. The server
 * (App\Services\FieldInstallationService) stays the source of truth — it
 * re-checks every amount and refuses a receipt whose amount differs.
 */

import type { InstallationRequestFields, ServicePlan } from './installation';

/** One ticked row of the services picker (same shape as the API's services[]). */
export interface ServiceSelectionLike {
    service_uuid: string;
    service_variant_uuid: string | null;
    price: string;
}

/** A receipt the phone printed from its block, as the sync item carries it. */
export interface FieldInstallationReceipt {
    local_uuid: string;
    receipt_number: string | null;
    security_code: string | null;
    receipt_issued_at: string | null;
    /** The amount the receipt was signed for. */
    amount?: string;
}

/** `changes.installations[]` on POST /sync/push. */
export interface FieldInstallationItem extends InstallationRequestFields {
    local_uuid: string;
    customer_uuid: string;
    created_at: string;
    zone_uuid: string;
    name: string;
    phone: string;
    location: string | null;
    email: string | null;
    preferred_channel: string | null;
    level: string | null;
    services: ServiceSelectionLike[];
    installation_receipt: FieldInstallationReceipt | null;
    upfront_receipt: FieldInstallationReceipt | null;
}

function cents(value: string | number): number {
    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}

/** "5000.00" — the 2-decimal form the server and the receipt code use. */
export function toAmountString(centsValue: number): string {
    return (centsValue / 100).toFixed(2);
}

/**
 * What each ticked service costs a month (its base row plus any options) —
 * the price months paid upfront are bought at, exactly as the server sums it.
 */
export function servicePrices(services: ServiceSelectionLike[]): Record<string, number> {
    const prices: Record<string, number> = {};

    for (const row of services) {
        prices[row.service_uuid] = (prices[row.service_uuid] ?? 0) + cents(row.price);
    }

    return prices;
}

/** Months paid upfront, per service, at each service's price — in cents. */
export function upfrontCents(services: ServiceSelectionLike[], plans: ServicePlan[]): number {
    const prices = servicePrices(services);

    return plans.reduce((sum, plan) => {
        const months = Math.max(0, Math.floor(plan.paid_months) || 0);

        return months > 0 && prices[plan.service_uuid] !== undefined ? sum + months * prices[plan.service_uuid] : sum;
    }, 0);
}

/** The installation amount paid now, in cents (blank = nothing). */
export function installationPaidCents(paidNow: string | null): number {
    return paidNow === null || paidNow.trim() === '' ? 0 : Math.max(0, cents(paidNow));
}

/** What the agent collects now: the installation paid plus the months paid upfront. */
export function collectNowCents(paidNow: string | null, services: ServiceSelectionLike[], plans: ServicePlan[]): number {
    return installationPaidCents(paidNow) + upfrontCents(services, plans);
}

/** The phone-side checks before a registration is queued (the server repeats them). */
export function fieldRegistrationErrors(input: {
    name: string;
    phone: string;
    zoneUuid: string | null;
    services: ServiceSelectionLike[];
    fee: string | null;
    paidNow: string | null;
}): Record<string, string> {
    const errors: Record<string, string> = {};

    if (!input.name.trim()) {
        errors.name = 'Name is required.';
    }

    if (!input.phone.trim()) {
        errors.phone = 'Phone is required.';
    }

    if (!input.zoneUuid) {
        errors.zone_uuid = 'Select a zone.';
    }

    if (input.services.length === 0) {
        errors.services = 'Select at least one service.';
    }

    const fee = input.fee === null || input.fee.trim() === '' ? null : cents(input.fee);
    const paid = installationPaidCents(input.paidNow);

    if (fee !== null && paid > fee) {
        errors.installation_paid = 'More than the installation fee cannot be paid toward it.';
    }

    return errors;
}

/** The sync item for one registration. */
export function buildFieldInstallationItem(input: {
    localUuid: string;
    customerUuid: string;
    createdAt: string;
    zoneUuid: string;
    name: string;
    phone: string;
    location: string;
    email: string;
    preferredChannel: string | null;
    level: string | null;
    services: ServiceSelectionLike[];
    request: InstallationRequestFields;
    installationReceipt: FieldInstallationReceipt | null;
    upfrontReceipt: FieldInstallationReceipt | null;
}): FieldInstallationItem {
    const upfront = upfrontCents(input.services, input.request.service_plans);
    const paid = installationPaidCents(input.request.installation_paid);

    return {
        ...input.request,
        // Exactly the amount the installation receipt was signed for.
        installation_paid: paid > 0 ? toAmountString(paid) : null,
        local_uuid: input.localUuid,
        customer_uuid: input.customerUuid,
        created_at: input.createdAt,
        zone_uuid: input.zoneUuid,
        name: input.name.trim(),
        phone: input.phone.trim(),
        location: input.location.trim() || null,
        email: input.email.trim() || null,
        preferred_channel: input.preferredChannel,
        level: input.level,
        services: input.services,
        installation_receipt: input.installationReceipt,
        upfront_receipt: input.upfrontReceipt ? { ...input.upfrontReceipt, amount: toAmountString(upfront) } : null,
    };
}

export type FieldInstallationSyncStatus = 'queued' | 'failed' | 'synced';
export type FieldInstallationActivation = 'pending' | 'activated' | 'rejected' | null;

/**
 * Where a registration is, for the sync status and history screens:
 * waiting to sync → (failed: reason) → Pending activation → Activated /
 * Rejected: reason.
 */
export function fieldInstallationStatus(row: {
    sync_status: FieldInstallationSyncStatus;
    sync_error: string | null;
    activation: FieldInstallationActivation;
    decision_reason: string | null;
}): { label: string; tone: 'warning' | 'danger' | 'success' | 'neutral' } {
    if (row.sync_status === 'queued') {
        return { label: 'Waiting to sync', tone: 'warning' };
    }

    if (row.sync_status === 'failed') {
        return { label: `Not synced: ${row.sync_error ?? 'sync failed'}`, tone: 'danger' };
    }

    if (row.activation === 'activated') {
        return { label: 'Activated', tone: 'success' };
    }

    if (row.activation === 'rejected') {
        return { label: `Rejected: ${row.decision_reason ?? 'no reason given'}`, tone: 'danger' };
    }

    return { label: 'Pending activation', tone: 'neutral' };
}

/**
 * The phone's own duplicate check against its cached customers — same rule
 * as the server (CustomerInstallationService::possibleDuplicates): the same
 * phone number (last nine digits) or exactly the same name. The server
 * checks again at sync and flags the registration for the office.
 */
export function isPossibleDuplicate(candidate: { name: string; phone: string | null }, name: string, phone: string): boolean {
    const digits = phone.replace(/\D/g, '');
    const lastNine = digits.length >= 9 ? digits.slice(-9) : null;
    const candidateDigits = (candidate.phone ?? '').replace(/\D/g, '');

    if (lastNine !== null && candidateDigits.endsWith(lastNine)) {
        return true;
    }

    const trimmed = name.trim().toLowerCase();

    return trimmed.length >= 3 && candidate.name.trim().toLowerCase() === trimmed;
}

/** A server older than phase 3 drops the `installations` channel: its results carry none. */
export function serverAcceptsInstallations(results: { installations?: unknown }): boolean {
    return Array.isArray(results.installations);
}

export const OLD_SERVER_MESSAGE = 'The server does not accept field registrations yet — kept on this phone and retried on every sync.';
