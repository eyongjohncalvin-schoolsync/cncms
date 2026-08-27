import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateComplaintForm, validateExpenditureForm } from '../validation';

test('rejects a missing description — mobile is deliberately stricter than the web form', () => {
    const result = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: '2500',
        description: '   ',
        dateText: '2026-08-23',
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.description);
        assert.equal(result.errors.amount, undefined);
    }
});

test('rejects a missing category', () => {
    const result = validateExpenditureForm({
        categoryUuid: null,
        amountText: '2500',
        description: 'Fuel for zone rounds',
        dateText: '2026-08-23',
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.category);
    }
});

test('rejects a zero or negative amount', () => {
    const zero = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: '0',
        description: 'Fuel',
        dateText: '2026-08-23',
    });
    const negative = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: '-500',
        description: 'Fuel',
        dateText: '2026-08-23',
    });
    const nonNumeric = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: 'abc',
        description: 'Fuel',
        dateText: '2026-08-23',
    });

    assert.equal(zero.valid, false);
    assert.equal(negative.valid, false);
    assert.equal(nonNumeric.valid, false);
});

test('rejects a malformed date', () => {
    const result = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: '2500',
        description: 'Fuel',
        dateText: '23-08-2026',
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.date);
    }
});

test('accepts a fully valid form and returns the parsed amount', () => {
    const result = validateExpenditureForm({
        categoryUuid: 'cat-1',
        amountText: '3500.50',
        description: 'Fuel for zone rounds',
        dateText: '2026-08-23',
    });

    assert.equal(result.valid, true);
    if (result.valid) {
        assert.equal(result.amount, 3500.5);
    }
});

// --- validateComplaintForm — complaint-desk.md section 7 ---

test('complaint form: rejects a missing category', () => {
    const result = validateComplaintForm({
        category: null,
        title: 'Zone list will not sync',
        description: 'Happens every morning.',
        customerUuid: null,
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.category);
    }
});

test('complaint form: rejects a blank title', () => {
    const result = validateComplaintForm({
        category: 'operational',
        title: '   ',
        description: 'Some detail.',
        customerUuid: null,
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.title);
    }
});

/**
 * Deliberately stricter than the web form here: StoreComplaintRequest.php
 * does validate description as `required`, but resources/tsx/pages/
 * Complaints/Create.tsx's own UI labels the (collapsed) field "optional" —
 * a real web UX papercut. Mobile enforces it up front so a queued
 * complaint's amber "Saved · will sync" badge can never be followed by a
 * silent permanent sync failure — see src/utils/validation.ts's doc
 * comment on ComplaintFormInput.description.
 */
test('complaint form: rejects a blank description even though it renders as a collapsed "optional" field', () => {
    const result = validateComplaintForm({
        category: 'operational',
        title: 'Zone list will not sync',
        description: '   ',
        customerUuid: null,
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.description);
    }
});

test('complaint form: a customer complaint requires a selected customer', () => {
    const result = validateComplaintForm({
        category: 'customer',
        title: 'Signal keeps dropping',
        description: 'Relayed during a route visit.',
        customerUuid: null,
    });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.customer);
    }
});

test('complaint form: an operational complaint never requires a customer', () => {
    const result = validateComplaintForm({
        category: 'operational',
        title: 'Manuscript numbers look wrong',
        description: 'Arrears total does not match paper records.',
        customerUuid: null,
    });

    assert.equal(result.valid, true);
});

test('complaint form: accepts a fully valid customer complaint', () => {
    const result = validateComplaintForm({
        category: 'customer',
        title: 'Signal keeps dropping',
        description: 'Relayed during a route visit.',
        customerUuid: 'cust-uuid-123',
    });

    assert.equal(result.valid, true);
});
