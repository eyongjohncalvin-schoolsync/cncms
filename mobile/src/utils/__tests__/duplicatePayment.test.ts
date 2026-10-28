import { test } from 'node:test';
import assert from 'node:assert/strict';
import { REPEAT_BLOCK_MS, earlierFromLastSync, earlierFromServer, isDuplicateConfirmationComplete, startOfBusinessMonthIso } from '../duplicatePayment';

test('the server names who recorded the earlier payment — "you" for this agent', () => {
    const base = { uuid: 'p', amount: '2500.00', collected_at: '2026-10-12T09:00:00Z', receipt_number: '000231', verification_status: 'verified' };

    assert.deepEqual(earlierFromServer({ ...base, recorded_by: 'Office Clerk', recorded_by_me: false }), {
        amount: 2500,
        at: '2026-10-12T09:00:00Z',
        recordedBy: 'Office Clerk',
        receiptNumber: '000231',
        source: 'server',
    });
    assert.equal(earlierFromServer({ ...base, recorded_by: 'Ashu', recorded_by_me: true }).recordedBy, 'you');
});

test('offline, the last sync warns about this month only', () => {
    const now = new Date('2026-10-25T10:00:00Z');
    const paid = (at: string) => ({ paid_this_month: JSON.stringify({ amount: '2500.00', collected_at: at, recorded_by: 'Office Clerk' }) });

    assert.equal(earlierFromLastSync(paid('2026-10-12T09:00:00Z'), now)?.recordedBy, 'Office Clerk');
    assert.equal(earlierFromLastSync(paid('2026-09-28T09:00:00Z'), now), null, 'Last month is not this month.');
    assert.equal(earlierFromLastSync({ paid_this_month: null }, now), null);
    assert.equal(earlierFromLastSync({ paid_this_month: 'not json' }, now), null);
});

test('an exact repeat is refused for half an hour', () => {
    assert.equal(REPEAT_BLOCK_MS, 30 * 60 * 1000);
});

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
