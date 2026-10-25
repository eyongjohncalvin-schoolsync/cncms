import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isDuplicateConfirmationComplete, startOfBusinessMonthIso } from '../duplicatePayment';

test('the business month starts at midnight West Africa Time', () => {
    assert.equal(startOfBusinessMonthIso(new Date('2026-10-25T13:00:00Z')), '2026-09-30T23:00:00.000Z');
});

test('just after midnight WAT on the 1st is already the new month', () => {
    // 2026-11-01 00:30 WAT is 2026-10-31 23:30 UTC.
    assert.equal(startOfBusinessMonthIso(new Date('2026-10-31T23:30:00Z')), '2026-10-31T23:00:00.000Z');
});

test('January rolls back to the previous year correctly', () => {
    assert.equal(startOfBusinessMonthIso(new Date('2027-01-15T10:00:00Z')), '2026-12-31T23:00:00.000Z');
});

test('a reason is needed, and a note for "other"', () => {
    assert.equal(isDuplicateConfirmationComplete(null, ''), false);
    assert.equal(isDuplicateConfirmationComplete('advance', ''), true);
    assert.equal(isDuplicateConfirmationComplete('other', '  '), false);
    assert.equal(isDuplicateConfirmationComplete('other', 'Paid for his brother'), true);
});
