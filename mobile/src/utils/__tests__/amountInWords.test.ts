import { test } from 'node:test';
import assert from 'node:assert/strict';
import { amountInWords, numberToWords } from '../amountInWords';

test('numberToWords: small numbers and teens', () => {
    assert.equal(numberToWords(0), 'zero');
    assert.equal(numberToWords(7), 'seven');
    assert.equal(numberToWords(13), 'thirteen');
    assert.equal(numberToWords(40), 'forty');
    assert.equal(numberToWords(42), 'forty-two');
});

test('numberToWords: hundreds use the British "and"', () => {
    assert.equal(numberToWords(100), 'one hundred');
    assert.equal(numberToWords(105), 'one hundred and five');
    assert.equal(numberToWords(999), 'nine hundred and ninety-nine');
});

test('numberToWords: thousands and millions', () => {
    assert.equal(numberToWords(1000), 'one thousand');
    assert.equal(numberToWords(2005), 'two thousand and five');
    assert.equal(numberToWords(12500), 'twelve thousand five hundred');
    assert.equal(numberToWords(125000), 'one hundred and twenty-five thousand');
    assert.equal(numberToWords(1_000_000), 'one million');
    assert.equal(numberToWords(2_500_750), 'two million five hundred thousand seven hundred and fifty');
    assert.equal(numberToWords(1_000_001), 'one million and one');
});

test('numberToWords: rounds fractions, rejects negatives and non-finite values', () => {
    assert.equal(numberToWords(10.4), 'ten');
    assert.equal(numberToWords(-5), '');
    assert.equal(numberToWords(Number.NaN), '');
    assert.equal(numberToWords(Number.POSITIVE_INFINITY), '');
});

test('amountInWords: capitalised with the currency, empty for nothing typed', () => {
    assert.equal(amountInWords(12500), 'Twelve thousand five hundred francs CFA');
    assert.equal(amountInWords(1), 'One franc CFA');
    assert.equal(amountInWords(0), '');
    assert.equal(amountInWords(-100), '');
});
