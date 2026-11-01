import { test } from 'node:test';
import assert from 'node:assert/strict';
import { HOLD_THRESHOLD_FCFA, needsHold } from '../holdToConfirm';

test('from 100 000 FCFA a decision needs a hold', () => {
    assert.equal(HOLD_THRESHOLD_FCFA, 100000);
    assert.equal(needsHold(100000), true);
    assert.equal(needsHold('250000.00'), true);
    assert.equal(needsHold(99999), false);
    assert.equal(needsHold('25000.00'), false);
});

test('a big decrease counts as big too', () => {
    assert.equal(needsHold(-150000), true);
    assert.equal(needsHold('-150000.00'), true);
});

test('no amount, no hold', () => {
    assert.equal(needsHold(null), false);
    assert.equal(needsHold(undefined), false);
    assert.equal(needsHold(''), false);
    assert.equal(needsHold('abc'), false);
});
