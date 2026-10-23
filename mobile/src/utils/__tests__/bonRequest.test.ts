import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BON_PURPOSE_MAX, validateBonRequestForm, type BonRequestFormInput } from '../validation';

const valid: BonRequestFormInput = {
    categoryUuid: 'cat-1',
    amountText: '4500',
    payeeName: 'Garage Mbeng',
    purpose: 'Puncture repair on the zone motorbike',
    neededOnText: '2026-10-23',
    today: '2026-10-23',
};

test('accepts a complete bon request and returns the amount', () => {
    const result = validateBonRequestForm(valid);

    assert.equal(result.valid, true);
    if (result.valid) {
        assert.equal(result.amount, 4500);
    }
});

test('every bon needs a category so the money reaches the P&L', () => {
    const result = validateBonRequestForm({ ...valid, categoryUuid: null });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.category);
    }
});

test('rejects zero, negative and over-precise amounts', () => {
    for (const amountText of ['0', '-5', 'abc', '', '12.345']) {
        const result = validateBonRequestForm({ ...valid, amountText });
        assert.equal(result.valid, false, amountText);
    }
});

test('needs a payee and a purpose within the server limits', () => {
    const result = validateBonRequestForm({ ...valid, payeeName: '  ', purpose: 'x'.repeat(BON_PURPOSE_MAX + 1) });

    assert.equal(result.valid, false);
    if (!result.valid) {
        assert.ok(result.errors.payee);
        assert.ok(result.errors.purpose);
    }
});

test('the day it is needed cannot be in the past', () => {
    assert.equal(validateBonRequestForm({ ...valid, neededOnText: '2026-10-22' }).valid, false);
    assert.equal(validateBonRequestForm({ ...valid, neededOnText: '2026-11-02' }).valid, true);
    assert.equal(validateBonRequestForm({ ...valid, neededOnText: '23/10/2026' }).valid, false);
});
