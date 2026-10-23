import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    buildFieldInstallationItem,
    collectNowCents,
    fieldInstallationStatus,
    fieldRegistrationErrors,
    isPossibleDuplicate,
    serverAcceptsInstallations,
    servicePrices,
    toAmountString,
    upfrontCents,
} from '../fieldInstallation';
import { toRequestFields, initialInstallationState } from '../installation';

const tv = { service_uuid: 'tv', service_variant_uuid: null, price: '2500' };
const tvOption = { service_uuid: 'tv', service_variant_uuid: 'canal', price: '1000.00' };
const internet = { service_uuid: 'net', service_variant_uuid: null, price: '5000.50' };

test('a service costs its base row plus its options, as the server sums it', () => {
    assert.deepEqual(servicePrices([tv, tvOption, internet]), { tv: 350000, net: 500050 });
});

test('months paid upfront are bought per service at that service price', () => {
    const plans = [
        { service_uuid: 'tv', free_from: '2026-10', free_months: 1, paid_months: 2 },
        { service_uuid: 'net', free_from: null, free_months: 0, paid_months: 1 },
    ];

    assert.equal(upfrontCents([tv, tvOption, internet], plans), 2 * 350000 + 500050);
    assert.equal(toAmountString(upfrontCents([tv], plans)), '5000.00');
});

test('a plan on a service not ticked buys nothing', () => {
    assert.equal(upfrontCents([tv], [{ service_uuid: 'net', free_from: null, free_months: 0, paid_months: 3 }]), 0);
});

test('collect now is the installation paid plus the months paid upfront', () => {
    const plans = [{ service_uuid: 'tv', free_from: null, free_months: 0, paid_months: 2 }];

    assert.equal(collectNowCents('10000', [tv], plans), 1500000);
    assert.equal(collectNowCents('', [tv], []), 0);
    assert.equal(collectNowCents(null, [tv], plans), 500000);
});

test('the phone refuses an empty or overpaid registration before queueing it', () => {
    assert.deepEqual(
        Object.keys(fieldRegistrationErrors({ name: ' ', phone: '', zoneUuid: null, services: [], fee: null, paidNow: null })).sort(),
        ['name', 'phone', 'services', 'zone_uuid'],
    );
    assert.equal(
        fieldRegistrationErrors({ name: 'A', phone: '6', zoneUuid: 'z', services: [tv], fee: '10000', paidNow: '12000' }).installation_paid,
        'More than the installation fee cannot be paid toward it.',
    );
    assert.deepEqual(fieldRegistrationErrors({ name: 'A', phone: '6', zoneUuid: 'z', services: [tv], fee: '', paidNow: '5000' }), {});
});

test('the sync item carries the customer, the plan and both signed receipts', () => {
    const state = {
        ...initialInstallationState('2026-10-10', 'offer-1'),
        paidNow: '10000',
        servicePlans: [{ service_uuid: 'tv', free_from: '2026-10', free_months: 1, paid_months: 2 }],
    };
    const receipt = { local_uuid: 'r1', receipt_number: 'RCP-1', security_code: 'ABCD1234', receipt_issued_at: '2026-10-10T08:00:00Z' };

    const item = buildFieldInstallationItem({
        localUuid: 'l1',
        customerUuid: 'c1',
        createdAt: '2026-10-10T08:00:00Z',
        zoneUuid: 'z1',
        name: ' Ekema Divine ',
        phone: ' 677123456 ',
        location: '',
        email: '',
        preferredChannel: 'whatsapp',
        level: 'normal',
        services: [tv],
        request: toRequestFields(state),
        installationReceipt: receipt,
        upfrontReceipt: { ...receipt, local_uuid: 'r2', receipt_number: 'RCP-2' },
    });

    assert.equal(item.name, 'Ekema Divine');
    assert.equal(item.phone, '677123456');
    assert.equal(item.location, null);
    assert.equal(item.installation_paid, '10000.00');
    assert.equal(item.installation_offer_uuid, 'offer-1');
    assert.equal(item.upfront_receipt?.amount, '5000.00');
    assert.equal(item.installation_receipt?.receipt_number, 'RCP-1');
    assert.deepEqual(item.service_plans, [{ service_uuid: 'tv', free_from: '2026-10', free_months: 1, paid_months: 2 }]);
});

test('nothing paid now sends no installation amount', () => {
    const item = buildFieldInstallationItem({
        localUuid: 'l1',
        customerUuid: 'c1',
        createdAt: 'now',
        zoneUuid: 'z1',
        name: 'A',
        phone: '6',
        location: '',
        email: '',
        preferredChannel: null,
        level: null,
        services: [tv],
        request: toRequestFields(initialInstallationState('2026-10-10', null)),
        installationReceipt: null,
        upfrontReceipt: null,
    });

    assert.equal(item.installation_paid, null);
    assert.equal(item.upfront_receipt, null);
});

test('the status line follows the registration from the outbox to the office decision', () => {
    const base = { sync_error: null, activation: null, decision_reason: null } as const;

    assert.equal(fieldInstallationStatus({ ...base, sync_status: 'queued' }).label, 'Waiting to sync');
    assert.equal(fieldInstallationStatus({ ...base, sync_status: 'failed', sync_error: 'You can only register customers in your own zone.' }).label, 'Not synced: You can only register customers in your own zone.');
    assert.equal(fieldInstallationStatus({ ...base, sync_status: 'synced', activation: 'pending' }).label, 'Pending activation');
    assert.equal(fieldInstallationStatus({ ...base, sync_status: 'synced', activation: 'activated' }).tone, 'success');
    assert.equal(fieldInstallationStatus({ ...base, sync_status: 'synced', activation: 'rejected', decision_reason: 'Duplicate' }).label, 'Rejected: Duplicate');
});

test('an older server is recognised by the missing installations results', () => {
    assert.equal(serverAcceptsInstallations({ installations: [] }), true);
    assert.equal(serverAcceptsInstallations({}), false);
});

test('the phone flags a possible duplicate by phone or exact name, like the server', () => {
    assert.equal(isPossibleDuplicate({ name: 'Someone', phone: '+237 677 12 34 56' }, 'New Person', '677123456'), true);
    assert.equal(isPossibleDuplicate({ name: 'Ekema Divine', phone: null }, ' ekema divine ', ''), true);
    assert.equal(isPossibleDuplicate({ name: 'Ekema Divine', phone: '699000000' }, 'Ekema', '677123456'), false);
    assert.equal(isPossibleDuplicate({ name: 'Al', phone: null }, 'al', '12'), false);
});
