import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyKeypadKey, formatGroupedAmount, keypadValueToNumber, numberToKeypadValue } from '../keypad';

test('applyKeypadKey appends digits and the 000 key', () => {
    assert.equal(applyKeypadKey('', '1'), '1');
    assert.equal(applyKeypadKey('12', '5'), '125');
    assert.equal(applyKeypadKey('12', '000'), '12000');
});

test('applyKeypadKey ignores leading zeros', () => {
    assert.equal(applyKeypadKey('', '0'), '');
    assert.equal(applyKeypadKey('', '000'), '');
    assert.equal(applyKeypadKey('5', '0'), '50');
});

test('applyKeypadKey backspace and clear', () => {
    assert.equal(applyKeypadKey('125', 'back'), '12');
    assert.equal(applyKeypadKey('', 'back'), '');
    assert.equal(applyKeypadKey('125', 'clear'), '');
});

test('applyKeypadKey refuses input past the digit limit', () => {
    assert.equal(applyKeypadKey('1234', '5', 4), '1234');
    assert.equal(applyKeypadKey('12', '000', 4), '12');
    assert.equal(applyKeypadKey('123', '4', 4), '1234');
});

test('formatGroupedAmount groups thousands with spaces', () => {
    assert.equal(formatGroupedAmount(''), '0');
    assert.equal(formatGroupedAmount('500'), '500');
    assert.equal(formatGroupedAmount('12500'), '12 500');
    assert.equal(formatGroupedAmount('1250000'), '1 250 000');
    assert.equal(formatGroupedAmount(12500.4), '12 500');
});

test('keypad value <-> number conversions', () => {
    assert.equal(keypadValueToNumber(''), 0);
    assert.equal(keypadValueToNumber('12500'), 12500);
    assert.equal(numberToKeypadValue(12500), '12500');
    assert.equal(numberToKeypadValue(0), '');
    assert.equal(numberToKeypadValue(Number.NaN), '');
});
