import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    addMonths,
    defaultPlan,
    formatPeriod,
    initialInstallationState,
    installationMonth,
    isFreeMonth,
    promoLabel,
    syncPlans,
    takesMoney,
    toggleFreeMonth,
    toRequestFields,
} from '../installation';

const offer = { uuid: 'offer-1', fee: '10000.00', months: [{ service_uuid: 'tv', months: 2 }] };

test('addMonths crosses the year', () => {
    assert.equal(addMonths('2026-11', 2), '2027-01');
    assert.equal(addMonths('2026-10', 0), '2026-10');
});

test('installationMonth: the connection month, never a past month', () => {
    assert.equal(installationMonth('2026-10-10', '2026-10-23'), '2026-10');
    assert.equal(installationMonth('2026-08-30', '2026-10-23'), '2026-10');
    assert.equal(installationMonth('', '2026-10-23'), '2026-10');
});

test('defaultPlan: the installation month free by default, the offer preset when picked', () => {
    assert.deepEqual(defaultPlan('tv', '2026-10', null), { service_uuid: 'tv', free_from: '2026-10', free_months: 1, paid_months: 0 });
    assert.deepEqual(defaultPlan('tv', '2026-10', offer), { service_uuid: 'tv', free_from: '2026-10', free_months: 2, paid_months: 0 });
    assert.deepEqual(defaultPlan('internet', '2026-10', offer), { service_uuid: 'internet', free_from: null, free_months: 0, paid_months: 0 });
});

test('syncPlans: keeps chosen plans, adds defaults for newly ticked services', () => {
    const chosen = { service_uuid: 'tv', free_from: '2026-11', free_months: 1, paid_months: 3 };

    assert.deepEqual(syncPlans([chosen], ['tv', 'internet'], '2026-10', null), [chosen, defaultPlan('internet', '2026-10', null)]);
    assert.deepEqual(syncPlans([chosen], ['internet'], '2026-10', null), [defaultPlan('internet', '2026-10', null)]);
});

test('toggleFreeMonth keeps one consecutive run', () => {
    const plan = defaultPlan('tv', '2026-10', null);

    const extended = toggleFreeMonth(plan, '2026-12', '2026-10');
    assert.deepEqual([extended.free_from, extended.free_months], ['2026-10', 3]);

    const cut = toggleFreeMonth(extended, '2026-11', '2026-10');
    assert.deepEqual([cut.free_from, cut.free_months], ['2026-10', 1]);

    // Untick the installation month: it is billed, the run moves on.
    const unticked = toggleFreeMonth(extended, '2026-10', '2026-10');
    assert.deepEqual([unticked.free_from, unticked.free_months], ['2026-11', 2]);

    const none = toggleFreeMonth(plan, '2026-10', '2026-10');
    assert.deepEqual([none.free_from, none.free_months], [null, 0]);
    assert.equal(isFreeMonth(unticked, '2026-10'), false);
    assert.equal(isFreeMonth(unticked, '2026-12'), true);
});

test('toRequestFields: blanks become null; plans cleaned', () => {
    const state = {
        ...initialInstallationState('2026-10-10', 'offer-1'),
        paidNow: ' 10000 ',
        servicePlans: [{ service_uuid: 'tv', free_from: '2026-10', free_months: 2, paid_months: 3.7 }],
    };

    assert.deepEqual(toRequestFields(state), {
        connected_on: '2026-10-10',
        installation_offer_uuid: 'offer-1',
        installation_fee: null,
        installation_paid: '10000',
        installation_reason: null,
        service_plans: [{ service_uuid: 'tv', free_from: '2026-10', free_months: 2, paid_months: 3 }],
        method: 'cash',
        method_reference: null,
    });
});

test('takesMoney: installation paid now or months paid upfront', () => {
    const state = initialInstallationState('2026-10-10', null);

    assert.equal(takesMoney(state), false);
    assert.equal(takesMoney({ ...state, paidNow: '5000' }), true);
    assert.equal(takesMoney({ ...state, servicePlans: [{ service_uuid: 'tv', free_from: null, free_months: 0, paid_months: 1 }] }), true);
});

test('formatPeriod: YYYY-MM to "Mon YYYY"', () => {
    assert.equal(formatPeriod('2026-11'), 'Nov 2026');
    assert.equal(formatPeriod('garbage'), 'garbage');
});

test('promoLabel: FREE, PREPAID and per-service paid months told apart', () => {
    assert.equal(
        promoLabel({ is_free: true, free_months: [{ service: 'TV', from: '2026-10', until: '2026-11' }] }, '2026-10'),
        'FREE · TV free until Nov 2026',
    );
    assert.equal(promoLabel({ prepaid_months_remaining: 3 }, '2026-11'), 'PREPAID · 3 months left');
    assert.equal(promoLabel({ service_paid_months: [{ service: 'TV', months: 2 }] }, '2026-11'), 'TV: 2 months paid upfront left');
    assert.equal(promoLabel({ free_months: [{ service: 'TV', from: '2026-08', until: '2026-09' }] }, '2026-11'), null);
    assert.equal(promoLabel(null, '2026-11'), null);
});
