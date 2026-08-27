import { test } from 'node:test';
import assert from 'node:assert/strict';
import { filterPaymentsByStatus, formatFrequency } from '../paymentFilters';
import type { VerificationStatus } from '../../types/db';

interface FakePayment {
    local_uuid: string;
    verification_status: VerificationStatus;
}

const sample: FakePayment[] = [
    { local_uuid: 'a', verification_status: 'pending' },
    { local_uuid: 'b', verification_status: 'verified' },
    { local_uuid: 'c', verification_status: 'rejected' },
    { local_uuid: 'd', verification_status: 'verified' },
];

test('"all" returns every payment untouched', () => {
    const result = filterPaymentsByStatus(sample, 'all');
    assert.equal(result.length, 4);
});

test('"pending" returns only pending payments', () => {
    const result = filterPaymentsByStatus(sample, 'pending');
    assert.deepEqual(result.map((p) => p.local_uuid), ['a']);
});

test('"verified" returns only verified payments', () => {
    const result = filterPaymentsByStatus(sample, 'verified');
    assert.deepEqual(result.map((p) => p.local_uuid), ['b', 'd']);
});

test('"rejected" returns only rejected payments', () => {
    const result = filterPaymentsByStatus(sample, 'rejected');
    assert.deepEqual(result.map((p) => p.local_uuid), ['c']);
});

test('an empty list stays empty for any filter', () => {
    assert.equal(filterPaymentsByStatus([], 'verified').length, 0);
});

test('formatFrequency: monthly/yearly are fixed labels', () => {
    assert.equal(formatFrequency('monthly', null), 'Monthly');
    assert.equal(formatFrequency('yearly', null), 'Yearly');
});

test('formatFrequency: months pluralizes correctly', () => {
    assert.equal(formatFrequency('months', 1), '1 month');
    assert.equal(formatFrequency('months', 3), '3 months');
});

test('formatFrequency: months with no count falls back gracefully', () => {
    assert.equal(formatFrequency('months', null), 'Multi-month');
    assert.equal(formatFrequency('months', 0), 'Multi-month');
});
