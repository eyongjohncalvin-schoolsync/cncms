import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dataOwnerKey, decideOnSignIn } from '../dataOwner';

const swecomKelvin = dataOwnerKey('swecom', 'u-kelvin');
const swecomDivine = dataOwnerKey('swecom', 'u-divine');
const mdcnKelvin = dataOwnerKey('mdcn', 'u-kelvin');

test('the same person of the same company keeps their data, unsent records included', () => {
    assert.equal(decideOnSignIn(swecomKelvin, swecomKelvin, true, 0), 'keep');
    assert.equal(decideOnSignIn(swecomKelvin, swecomKelvin, true, 3), 'keep');
});

test('another company signing in wipes the phone first', () => {
    assert.equal(decideOnSignIn(swecomKelvin, mdcnKelvin, true, 0), 'wipe');
});

test('another person of the same company starts clean too', () => {
    assert.equal(decideOnSignIn(swecomKelvin, swecomDivine, true, 0), 'wipe');
});

test("someone else's unsent records are never shown or sent: the sign-in is refused", () => {
    assert.equal(decideOnSignIn(swecomKelvin, mdcnKelvin, true, 2), 'refuse');
    assert.equal(decideOnSignIn(swecomKelvin, swecomDivine, false, 1), 'refuse');
});

test('a phone never stamped: empty is kept, anything on it is not trusted', () => {
    assert.equal(decideOnSignIn(null, mdcnKelvin, false, 0), 'keep');
    assert.equal(decideOnSignIn(null, mdcnKelvin, true, 0), 'wipe');
    assert.equal(decideOnSignIn(null, mdcnKelvin, true, 4), 'refuse');
});

test('the key carries both company and person', () => {
    assert.notEqual(dataOwnerKey('swecom', 'u-1'), dataOwnerKey('mdcn', 'u-1'));
    assert.equal(dataOwnerKey(undefined, 'u-1'), ':u-1');
});
