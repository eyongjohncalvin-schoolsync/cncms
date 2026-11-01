import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cashDifference, parseCashAmount } from '../cashAmount';

test('a counted amount may be written with thousands separators', () => {
    assert.equal(parseCashAmount('12 500'), '12500');
    assert.equal(parseCashAmount('12.500'), '12500');
    assert.equal(parseCashAmount(' 4500 '), '4500');
});

test('zero is a real count; leading zeros are dropped', () => {
    assert.equal(parseCashAmount('0'), '0');
    assert.equal(parseCashAmount('0500'), '500');
});

test('anything that is not whole FCFA is refused', () => {
    assert.equal(parseCashAmount(''), null);
    assert.equal(parseCashAmount('-500'), null);
    assert.equal(parseCashAmount('5k'), null);
    assert.equal(parseCashAmount('1234567890'), null);
});

test('the difference is negative when short and positive when over', () => {
    assert.equal(cashDifference('4500', '5000.00'), -500);
    assert.equal(cashDifference('5200', '5000.00'), 200);
    assert.equal(cashDifference('5000', '5000.00'), 0);
});
