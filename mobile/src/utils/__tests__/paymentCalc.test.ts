import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calculateGuideAmount, validatePaymentForm } from '../paymentCalc';

test('calculateGuideAmount: monthly is bill × 1', () => {
    assert.equal(calculateGuideAmount(5000, 'monthly', null), 5000);
});

test('calculateGuideAmount: yearly is bill × 12', () => {
    assert.equal(calculateGuideAmount(5000, 'yearly', null), 60000);
});

test('calculateGuideAmount: months is bill × N', () => {
    assert.equal(calculateGuideAmount(5000, 'months', 3), 15000);
    assert.equal(calculateGuideAmount(5000, 'months', 1), 5000);
});

test('calculateGuideAmount: months with no/invalid count returns null (never guesses)', () => {
    assert.equal(calculateGuideAmount(5000, 'months', null), null);
    assert.equal(calculateGuideAmount(5000, 'months', 0), null);
    assert.equal(calculateGuideAmount(5000, 'months', -2), null);
    assert.equal(calculateGuideAmount(5000, 'months', Number.NaN), null);
});

test('calculateGuideAmount: an invalid bill never produces a figure', () => {
    assert.equal(calculateGuideAmount(Number.NaN, 'monthly', null), null);
    assert.equal(calculateGuideAmount(-100, 'monthly', null), null);
});

test('calculateGuideAmount: zero bill is a valid figure, not treated as missing', () => {
    assert.equal(calculateGuideAmount(0, 'monthly', null), 0);
    assert.equal(calculateGuideAmount(0, 'yearly', null), 0);
});

test('validatePaymentForm: rejects with no customer selected', () => {
    const result = validatePaymentForm({ customerUuid: null, amount: '1000', frequency: 'monthly', months: '' });

    assert.equal(result.valid, false);
    assert.ok(result.errors.customer);
});

test('validatePaymentForm: rejects a zero, negative, empty, or non-numeric amount', () => {
    for (const amount of ['0', '-500', '', 'abc']) {
        const result = validatePaymentForm({ customerUuid: 'c-1', amount, frequency: 'monthly', months: '' });
        assert.equal(result.valid, false, `amount ${JSON.stringify(amount)} should be invalid`);
        assert.ok(result.errors.amount);
    }
});

test('validatePaymentForm: monthly/yearly never require a months value', () => {
    const monthly = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'monthly', months: '' });
    const yearly = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'yearly', months: '' });

    assert.equal(monthly.valid, true);
    assert.equal(yearly.valid, true);
    assert.equal(monthly.monthsValue, null);
});

test('validatePaymentForm: "months" frequency requires a whole number >= 1', () => {
    const missing = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'months', months: '' });
    const zero = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'months', months: '0' });
    const fractional = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'months', months: '1.5' });
    const valid = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'months', months: '3' });

    assert.equal(missing.valid, false);
    assert.equal(zero.valid, false);
    assert.equal(fractional.valid, false);
    assert.equal(valid.valid, true);
    assert.equal(valid.monthsValue, 3);
});

test('validatePaymentForm: matches StorePaymentRequest — credit/receipt are never required', () => {
    // No `credit` or `receipt` field exists on the validation input at all —
    // this test documents that omission is intentional, mirroring
    // App\Http\Requests\StorePaymentRequest's real rules (`credit` is
    // `nullable`, there is no receipt rule) rather than a mobile-only
    // stricter requirement. See mobile-app-react-native.md §4.
    const result = validatePaymentForm({ customerUuid: 'c-1', amount: '5000', frequency: 'monthly', months: '' });

    assert.equal(result.valid, true);
    assert.equal('credit' in result.errors, false);
    assert.equal('receipt' in result.errors, false);
});

test('validatePaymentForm: accepts a fully valid monthly form and returns the parsed amount', () => {
    const result = validatePaymentForm({ customerUuid: 'c-1', amount: '3500.50', frequency: 'monthly', months: '' });

    assert.equal(result.valid, true);
    assert.equal(result.amountValue, 3500.5);
});
