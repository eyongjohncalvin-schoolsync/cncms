import type { ExpenditureStatus, ExpenseLimitsApi } from '../types/api';
import type { PaymentMethod } from '../types/db';

/**
 * Pure logic behind the expense-controls UI (Record Expense hints, status
 * badges, category ranking) — mirrors App\Services\ExpenseControlService's
 * thresholds so the field worker sees, before saving, what the server will
 * do with the expense. These are HINTS only: the server decides, and never
 * rejects a sync for control reasons. Unit tested in
 * src/utils/__tests__/expenseControls.test.ts.
 */

export interface ExpenseLimits {
    autoApproveBelow: number;
    secondApprovalAbove: number;
    receiptRequiredFrom: number;
    supplierRequiredFrom: number;
    backdateDays: number;
}

function toAmount(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/** Numeric-string API shape -> numbers; null when anything is unusable. */
export function parseExpenseLimits(api: Partial<ExpenseLimitsApi> | null | undefined): ExpenseLimits | null {
    if (!api) {
        return null;
    }

    const autoApproveBelow = toAmount(api.auto_approve_below);
    const secondApprovalAbove = toAmount(api.second_approval_above);
    const receiptRequiredFrom = toAmount(api.receipt_required_from);
    const supplierRequiredFrom = toAmount(api.supplier_required_from);
    const backdateDays = toAmount(api.backdate_days);

    if (
        autoApproveBelow === null ||
        secondApprovalAbove === null ||
        receiptRequiredFrom === null ||
        supplierRequiredFrom === null ||
        backdateDays === null
    ) {
        return null;
    }

    return { autoApproveBelow, secondApprovalAbove, receiptRequiredFrom, supplierRequiredFrom, backdateDays };
}

export type ApprovalPath = 'auto' | 'manager' | 'manager_owner';

/** Same tiers as ExpenseControlService::tier(): above the second-approval
 * limit needs two people, from the auto limit up needs one. */
export function approvalPathFor(amount: number, limits: ExpenseLimits): ApprovalPath {
    if (amount > limits.secondApprovalAbove) {
        return 'manager_owner';
    }

    if (amount >= limits.autoApproveBelow) {
        return 'manager';
    }

    return 'auto';
}

export interface ApprovalHint {
    path: ApprovalPath;
    label: string;
    detail: string;
}

export function approvalHintFor(amount: number, limits: ExpenseLimits | null): ApprovalHint | null {
    if (!limits || !(amount > 0)) {
        return null;
    }

    const path = approvalPathFor(amount, limits);

    switch (path) {
        case 'auto':
            return { path, label: 'Approved instantly', detail: 'Small expenses are approved automatically.' };
        case 'manager':
            return { path, label: 'A manager will approve', detail: 'You will get a notification once it is checked.' };
        case 'manager_owner':
        default:
            return { path, label: 'Manager + owner approve', detail: 'Large expenses need two approvals.' };
    }
}

export function isReceiptRequired(amount: number, limits: ExpenseLimits | null): boolean {
    return !!limits && amount > 0 && amount >= limits.receiptRequiredFrom;
}

export function isSupplierRequired(amount: number, limits: ExpenseLimits | null): boolean {
    return !!limits && amount > 0 && amount >= limits.supplierRequiredFrom;
}

/** Whole calendar days between two `YYYY-MM-DD` dates (later minus earlier). */
export function daysBetween(fromDateOnly: string, toDateOnly: string): number {
    const from = Date.parse(`${fromDateOnly}T00:00:00Z`);
    const to = Date.parse(`${toDateOnly}T00:00:00Z`);

    if (Number.isNaN(from) || Number.isNaN(to)) {
        return 0;
    }

    return Math.round((to - from) / 86_400_000);
}

/** True when `spentAt` is further back than the company allows without a
 * manager looking at it (ExpenseControlService's "Dated in the past" flag). */
export function isBackdatedBeyondLimit(spentAt: string, today: string, limits: ExpenseLimits | null): boolean {
    if (!limits) {
        return false;
    }

    return daysBetween(spentAt, today) > limits.backdateDays;
}

export type ExpenseBadgeTone = 'verified' | 'pending' | 'rejected' | 'neutral' | 'warning';

export const EXPENSE_STATUS_DISPLAY: Record<ExpenditureStatus, { label: string; tone: ExpenseBadgeTone }> = {
    approved: { label: 'Approved', tone: 'verified' },
    pending_evidence: { label: 'Waiting for receipt', tone: 'warning' },
    pending_approval: { label: 'Waiting approval', tone: 'pending' },
    pending_second_approval: { label: 'Owner approval', tone: 'pending' },
    rejected: { label: 'Rejected', tone: 'rejected' },
    void: { label: 'Void', tone: 'neutral' },
};

export function expenseStatusDisplay(status: string | null | undefined): { label: string; tone: ExpenseBadgeTone } | null {
    if (!status) {
        return null;
    }

    return EXPENSE_STATUS_DISPLAY[status as ExpenditureStatus] ?? { label: status, tone: 'neutral' };
}

export const EXPENSE_PAYMENT_METHODS: Array<{ value: PaymentMethod; label: string }> = [
    { value: 'cash', label: 'Cash' },
    { value: 'mtn_momo', label: 'MTN' },
    { value: 'orange_money', label: 'Orange' },
    { value: 'bank', label: 'Bank' },
];

export function isMomoMethod(method: string | null | undefined): boolean {
    return method === 'mtn_momo' || method === 'orange_money';
}

export function paymentMethodLabel(method: string | null | undefined): string {
    switch (method) {
        case 'cash':
            return 'Cash';
        case 'mtn_momo':
            return 'MTN MoMo';
        case 'orange_money':
            return 'Orange Money';
        case 'bank':
            return 'Bank';
        default:
            return '—';
    }
}

export interface CategoryUsage {
    category_uuid: string;
    use_count: number;
    last_used_at: string | null;
}

/**
 * Most-used categories first (ties: most recently used first), then the
 * never-used ones in their admin-set order — so the one or two categories a
 * worker actually uses are always the first tiles under their thumb.
 */
export function rankCategoriesByUsage<T extends { uuid: string; sort_order: number }>(
    categories: T[],
    usage: CategoryUsage[],
): T[] {
    const usageByCategory = new Map(usage.map((row) => [row.category_uuid, row]));

    return [...categories].sort((a, b) => {
        const usageA = usageByCategory.get(a.uuid);
        const usageB = usageByCategory.get(b.uuid);
        const countA = usageA?.use_count ?? 0;
        const countB = usageB?.use_count ?? 0;

        if (countA !== countB) {
            return countB - countA;
        }

        const lastA = usageA?.last_used_at ?? '';
        const lastB = usageB?.last_used_at ?? '';

        if (lastA !== lastB) {
            return lastA < lastB ? 1 : -1;
        }

        return a.sort_order - b.sort_order;
    });
}

/** One-tap reasons on the approver's Reject sheet. */
export const REJECT_QUICK_REASONS = [
    'No receipt photo',
    'Amount looks wrong',
    'Not a company expense',
    'Duplicate of another expense',
    'Wrong category',
];
