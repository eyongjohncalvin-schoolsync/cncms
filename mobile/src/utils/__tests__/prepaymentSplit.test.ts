import { test } from 'node:test';
import assert from 'node:assert/strict';
import { splitPrepayment } from '../prepaymentSplit';

test('old debt is paid first, then whole months at the bill', () => {
    // Owes 2,000, bill 3,000, pays 11,000 for 3 months.
    assert.deepEqual(splitPrepayment(11000, 2000, 3000, 3), { debtCleared: 2000, months: 3, credit: 0 });
});

test('money that cannot buy a whole month is left as credit', () => {
    // 10,000 at a 3,000 bill, no debt: 3 months and 1,000 credit — never 4 months.
    assert.deepEqual(splitPrepayment(10000, 0, 3000, 4), { debtCleared: 0, months: 3, credit: 1000 });
});

test('the months asked for cap the months bought', () => {
    assert.deepEqual(splitPrepayment(12000, 0, 3000, 2), { debtCleared: 0, months: 2, credit: 6000 });
});

test('a payment smaller than the debt only reduces the debt', () => {
    assert.deepEqual(splitPrepayment(1500, 2000, 3000, 2), { debtCleared: 1500, months: 0, credit: 0 });
});
