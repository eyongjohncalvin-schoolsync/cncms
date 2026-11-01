import { test } from 'node:test';
import assert from 'node:assert/strict';
import { attemptsLeftMessage, LOCK_AFTER_MS, MAX_PIN_ATTEMPTS, pinProblem, shouldLockOnReturn } from '../appLockRules';

test('a PIN is 4 to 6 digits', () => {
    assert.equal(pinProblem('2580'), null);
    assert.equal(pinProblem('730194'), null);
    assert.equal(pinProblem('123'), 'Use 4 to 6 digits.');
    assert.equal(pinProblem('1234567'), 'Use 4 to 6 digits.');
    assert.equal(pinProblem('12a4'), 'Use 4 to 6 digits.');
});

test('the PINs anybody guesses first are refused', () => {
    assert.notEqual(pinProblem('0000'), null);
    assert.notEqual(pinProblem('111111'), null);
    assert.notEqual(pinProblem('1234'), null);
    assert.notEqual(pinProblem('987654'), null);
    assert.equal(pinProblem('1235'), null);
});

test('the app locks again only after a minute away', () => {
    const now = 1_000_000;
    assert.equal(shouldLockOnReturn(null, now), false);
    assert.equal(shouldLockOnReturn(now - 5_000, now), false);
    assert.equal(shouldLockOnReturn(now - LOCK_AFTER_MS, now), true);
});

test('wrong PINs count down to signing the phone out', () => {
    assert.equal(attemptsLeftMessage(1), `Wrong PIN. ${MAX_PIN_ATTEMPTS - 1} tries left.`);
    assert.equal(attemptsLeftMessage(MAX_PIN_ATTEMPTS - 1), 'Wrong PIN. One more wrong PIN signs this phone out.');
    assert.equal(attemptsLeftMessage(MAX_PIN_ATTEMPTS), null);
});
