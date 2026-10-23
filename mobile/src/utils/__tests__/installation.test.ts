import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatPeriod, freeMonthsFor, initialInstallationState, promoLabel, takesMoney, toRequestFields, withFreeMonths } from '../installation';

const offer = { uuid: 'offer-1', fee: '10000.00', months: [{ service_uuid: 'tv', months: 2 }] };

test('freeMonthsFor: the offer months unless edited', () => {
    assert.equal(freeMonthsFor(offer, null, 'tv'), 2);
    assert.equal(freeMonthsFor(offer, null, 'internet'), 0);
    assert.equal(freeMonthsFor(offer, [{ service_uuid: 'tv', months: 3 }], 'tv'), 3);
    assert.equal(freeMonthsFor(null, null, 'tv'), 0);
});

test('withFreeMonths: keeps the offer months of the other ticked services', () => {
    assert.deepEqual(withFreeMonths(offer, null, ['tv', 'internet'], 'internet', 1), [
        { service_uuid: 'tv', months: 2 },
        { service_uuid: 'internet', months: 1 },
    ]);
});

test('withFreeMonths: negative or invalid input becomes 0', () => {
    assert.deepEqual(withFreeMonths(offer, null, ['tv'], 'tv', -4), [{ service_uuid: 'tv', months: 0 }]);
    assert.deepEqual(withFreeMonths(offer, null, ['tv'], 'tv', Number.NaN), [{ service_uuid: 'tv', months: 0 }]);
});

test('toRequestFields: blanks become null, the offer fee is left to the server', () => {
    const state = { ...initialInstallationState('2026-10-10', 'offer-1'), paidNow: ' 10000 ', advanceMonths: 3 };

    assert.deepEqual(toRequestFields(state), {
        connected_on: '2026-10-10',
        installation_offer_uuid: 'offer-1',
        installation_fee: null,
        installation_paid: '10000',
        installation_reason: null,
        free_months: null,
        advance_months: 3,
        method: 'cash',
        method_reference: null,
    });
});

test('takesMoney: only when something is paid now or an advance is taken', () => {
    const state = initialInstallationState('2026-10-10', null);

    assert.equal(takesMoney(state), false);
    assert.equal(takesMoney({ ...state, paidNow: '5000' }), true);
    assert.equal(takesMoney({ ...state, advanceMonths: 1 }), true);
});

test('formatPeriod: YYYY-MM to "Mon YYYY"', () => {
    assert.equal(formatPeriod('2026-11'), 'Nov 2026');
    assert.equal(formatPeriod('2027-01'), 'Jan 2027');
    assert.equal(formatPeriod('garbage'), 'garbage');
});

test('promoLabel: FREE and PREPAID are told apart', () => {
    assert.equal(
        promoLabel({ is_free: true, free_months: [{ service: 'TV', from: '2026-11', until: '2026-12' }] }, '2026-11'),
        'FREE · TV free until Dec 2026',
    );
    assert.equal(promoLabel({ is_free: false, prepaid_months_remaining: 3 }, '2026-11'), 'PREPAID · 3 months left');
    assert.equal(promoLabel({ prepaid_months_remaining: 1 }, '2026-11'), 'PREPAID · 1 month left');
});

test('promoLabel: first billed month only while it is still ahead; ended windows dropped', () => {
    assert.equal(promoLabel({ billing_starts_period: '2026-11' }, '2026-10'), 'First billed month Nov 2026');
    assert.equal(promoLabel({ billing_starts_period: '2026-11', free_months: [{ service: 'TV', from: '2026-08', until: '2026-09' }] }, '2026-11'), null);
    assert.equal(promoLabel(null, '2026-11'), null);
});
