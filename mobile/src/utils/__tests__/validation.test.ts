import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    validateArrearsAdjustmentForm,
    validateComplaintForm,
    validateExpenditureForm,
    validatePasswordForm,
    validateProfileForm,
} from '../validation';

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

// --- validateProfileForm / validatePasswordForm — self-service profile &
// password update, mobile-app-react-native.md §11 addendum ---

test('profile form: rejects blank name/username/email', () => {
    const result = validateProfileForm({ name: '  ', username: '', email: '' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.name);
        assert.ok(result.errors.username);
        assert.ok(result.errors.email);
    }
});

test('profile form: rejects a malformed email', () => {
    const result = validateProfileForm({ name: 'Kelvin', username: 'kelvin', email: 'not-an-email' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.email);
        assert.equal(result.errors.name, undefined);
        assert.equal(result.errors.username, undefined);
    }
});

test('profile form: accepts a fully valid form', () => {
    const result = validateProfileForm({ name: 'Kelvin', username: 'kelvin', email: 'kelvin@example.test' });

    assert.equal(result.valid, true);
});

test('password form: rejects a blank current password', () => {
    const result = validatePasswordForm({ currentPassword: '', newPassword: 'newpass123', confirmPassword: 'newpass123' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.currentPassword);
    }
});

test('password form: rejects a new password under 8 characters', () => {
    const result = validatePasswordForm({ currentPassword: 'password', newPassword: 'short1', confirmPassword: 'short1' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.newPassword);
    }
});

test('password form: rejects a new password missing a number', () => {
    const result = validatePasswordForm({ currentPassword: 'password', newPassword: 'onlyletters', confirmPassword: 'onlyletters' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.newPassword);
    }
});

test('password form: rejects mismatched confirmation', () => {
    const result = validatePasswordForm({ currentPassword: 'password', newPassword: 'newpass123', confirmPassword: 'newpass124' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.confirmPassword);
    }
});

test('password form: accepts a fully valid change', () => {
    const result = validatePasswordForm({ currentPassword: 'password', newPassword: 'newpass123', confirmPassword: 'newpass123' });

    assert.equal(result.valid, true);
});

// --- validateArrearsAdjustmentForm — 2026-08-28 addendum ---

test('arrears adjustment form: rejects a malformed target period', () => {
    const result = validateArrearsAdjustmentForm({ targetPeriod: '2026-8', amountText: '1000', reasonNote: 'Billing error found in the field.' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.targetPeriod);
    }
});

test('arrears adjustment form: rejects a future target period', () => {
    const now = new Date();
    const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    const futurePeriod = `${nextMonth.getFullYear()}-${String(nextMonth.getMonth() + 1).padStart(2, '0')}`;

    const result = validateArrearsAdjustmentForm({ targetPeriod: futurePeriod, amountText: '1000', reasonNote: 'Should be rejected.' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.targetPeriod);
    }
});

test('arrears adjustment form: rejects a zero or negative amount', () => {
    const zero = validateArrearsAdjustmentForm({ targetPeriod: '2026-08', amountText: '0', reasonNote: 'Some note.' });
    const negative = validateArrearsAdjustmentForm({ targetPeriod: '2026-08', amountText: '-500', reasonNote: 'Some note.' });

    assert.equal(zero.valid, false);
    assert.equal(negative.valid, false);
    if (!zero.valid) {
        assert.ok(zero.errors.amount);
    }
});

test('arrears adjustment form: rejects a blank reason note', () => {
    const result = validateArrearsAdjustmentForm({ targetPeriod: '2026-08', amountText: '1000', reasonNote: '   ' });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.reasonNote);
    }
});

test('arrears adjustment form: accepts a fully valid form and returns the parsed amount', () => {
    const result = validateArrearsAdjustmentForm({
        targetPeriod: '2026-08',
        amountText: '2500.50',
        reasonNote: 'Goodwill credit for a multi-day outage.',
    });

    assert.equal(result.valid, true);
    if (result.valid) {
        assert.equal(result.amount, 2500.5);
    }
});
