import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    approvalHintFor,
    approvalPathFor,
    daysBetween,
    expenseStatusDisplay,
    isBackdatedBeyondLimit,
    isMomoMethod,
    isReceiptRequired,
    isSupplierRequired,
    parseExpenseLimits,
    paymentMethodLabel,
    rankCategoriesByUsage,
    type ExpenseLimits,
} from '../expenseControls';

const limits: ExpenseLimits = {
    autoApproveBelow: 5000,
    secondApprovalAbove: 50000,
    receiptRequiredFrom: 2000,
    supplierRequiredFrom: 10000,
    backdateDays: 1,
};

test('parseExpenseLimits converts numeric strings and rejects junk', () => {
    assert.deepEqual(
        parseExpenseLimits({
            auto_approve_below: '5000.00',
            second_approval_above: '50000.00',
            receipt_required_from: '2000.00',
            supplier_required_from: '10000.00',
            backdate_days: 1,
        }),
        limits,
    );
    assert.equal(parseExpenseLimits(null), null);
    assert.equal(parseExpenseLimits({ auto_approve_below: 'abc' }), null);
});

test('approvalPathFor mirrors the server tiers at the boundaries', () => {
    assert.equal(approvalPathFor(4999, limits), 'auto');
    assert.equal(approvalPathFor(5000, limits), 'manager');
    assert.equal(approvalPathFor(50000, limits), 'manager');
    assert.equal(approvalPathFor(50001, limits), 'manager_owner');
});

test('approvalHintFor gives worker-friendly labels, nothing without an amount or limits', () => {
    assert.equal(approvalHintFor(1000, limits)?.label, 'Approved instantly');
    assert.equal(approvalHintFor(8000, limits)?.label, 'A manager will approve');
    assert.equal(approvalHintFor(90000, limits)?.label, 'Manager + owner approve');
    assert.equal(approvalHintFor(0, limits), null);
    assert.equal(approvalHintFor(1000, null), null);
});

test('receipt and supplier requirements start at their thresholds', () => {
    assert.equal(isReceiptRequired(1999, limits), false);
    assert.equal(isReceiptRequired(2000, limits), true);
    assert.equal(isReceiptRequired(2000, null), false);
    assert.equal(isSupplierRequired(9999, limits), false);
    assert.equal(isSupplierRequired(10000, limits), true);
});

test('backdating: today/yesterday allowed with a 1-day limit, older is flagged', () => {
    assert.equal(daysBetween('2026-10-12', '2026-10-14'), 2);
    assert.equal(isBackdatedBeyondLimit('2026-10-14', '2026-10-14', limits), false);
    assert.equal(isBackdatedBeyondLimit('2026-10-13', '2026-10-14', limits), false);
    assert.equal(isBackdatedBeyondLimit('2026-10-12', '2026-10-14', limits), true);
    assert.equal(isBackdatedBeyondLimit('2026-10-13', '2026-10-14', { ...limits, backdateDays: 0 }), true);
    assert.equal(isBackdatedBeyondLimit('2026-01-01', '2026-10-14', null), false);
});

test('expenseStatusDisplay maps every server status, passes unknown ones through', () => {
    assert.deepEqual(expenseStatusDisplay('approved'), { label: 'Approved', tone: 'verified' });
    assert.deepEqual(expenseStatusDisplay('pending_evidence'), { label: 'Waiting for receipt', tone: 'warning' });
    assert.deepEqual(expenseStatusDisplay('pending_approval'), { label: 'Waiting approval', tone: 'pending' });
    assert.deepEqual(expenseStatusDisplay('pending_second_approval'), { label: 'Owner approval', tone: 'pending' });
    assert.deepEqual(expenseStatusDisplay('rejected'), { label: 'Rejected', tone: 'rejected' });
    assert.deepEqual(expenseStatusDisplay('void'), { label: 'Void', tone: 'neutral' });
    assert.deepEqual(expenseStatusDisplay('mystery'), { label: 'mystery', tone: 'neutral' });
    assert.equal(expenseStatusDisplay(null), null);
});

test('payment method helpers', () => {
    assert.equal(isMomoMethod('mtn_momo'), true);
    assert.equal(isMomoMethod('orange_money'), true);
    assert.equal(isMomoMethod('cash'), false);
    assert.equal(isMomoMethod(null), false);
    assert.equal(paymentMethodLabel('orange_money'), 'Orange Money');
    assert.equal(paymentMethodLabel(null), '—');
});

test('rankCategoriesByUsage: most used first, recency breaks ties, unused keep admin order', () => {
    const categories = [
        { uuid: 'fuel', sort_order: 1 },
        { uuid: 'tools', sort_order: 2 },
        { uuid: 'food', sort_order: 3 },
        { uuid: 'rent', sort_order: 4 },
        { uuid: 'misc', sort_order: 5 },
    ];

    const ranked = rankCategoriesByUsage(categories, [
        { category_uuid: 'food', use_count: 5, last_used_at: '2026-10-01T10:00:00Z' },
        { category_uuid: 'tools', use_count: 2, last_used_at: '2026-10-02T10:00:00Z' },
        { category_uuid: 'misc', use_count: 2, last_used_at: '2026-10-10T10:00:00Z' },
    ]);

    assert.deepEqual(
        ranked.map((c) => c.uuid),
        ['food', 'misc', 'tools', 'fuel', 'rent'],
    );
    // Does not mutate the input.
    assert.equal(categories[0].uuid, 'fuel');
});
