import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildLocalComplaintRow } from '../complaintOutbox';

const LOCAL_UUID = '11111111-1111-4111-8111-111111111111';
const NOW = '2026-08-25T12:00:00.000Z';

test('an operational complaint is queued offline-safe with no customer reference', () => {
    const row = buildLocalComplaintRow(
        { category: 'operational', title: "Zone 3 list won't sync", description: 'Happens every morning.', urgent: false },
        LOCAL_UUID,
        NOW,
    );

    assert.equal(row.local_uuid, LOCAL_UUID);
    assert.equal(row.server_uuid, null, 'a freshly queued complaint has no server_uuid yet');
    assert.equal(row.sync_status, 'queued', 'must be queued immediately, never left in a limbo state');
    assert.equal(row.category, 'operational');
    assert.equal(row.customer_uuid, null, 'operational complaints must never carry a customer reference');
    assert.equal(row.urgent, 0);
    assert.equal(row.sync_attempts, 0);
    assert.equal(row.sync_error, null);
    assert.equal(row.created_at, NOW);
    assert.equal(row.updated_at, NOW);
});

test('a customer complaint carries the customer_uuid through', () => {
    const row = buildLocalComplaintRow(
        {
            category: 'customer',
            title: 'Signal keeps dropping',
            description: 'Relayed during a route visit.',
            urgent: false,
            customer_uuid: 'cust-uuid-123',
        },
        LOCAL_UUID,
        NOW,
    );

    assert.equal(row.category, 'customer');
    assert.equal(row.customer_uuid, 'cust-uuid-123');
});

test('a customer_uuid is discarded for an operational complaint, even if one was accidentally passed in', () => {
    const row = buildLocalComplaintRow(
        {
            category: 'operational',
            title: 'Manuscript numbers look wrong',
            description: 'Arrears total does not match paper records.',
            urgent: false,
            customer_uuid: 'stale-customer-uuid',
        },
        LOCAL_UUID,
        NOW,
    );

    assert.equal(
        row.customer_uuid,
        null,
        'category=operational must always null out customer_uuid — mirrors the server-side rule (complaint-desk.md section 1) client-side',
    );
});

test('the urgent boolean maps to the 0/1 SQLite has no native boolean type', () => {
    const urgentRow = buildLocalComplaintRow(
        { category: 'operational', title: 'Cannot wait', description: 'Everything is down.', urgent: true },
        LOCAL_UUID,
        NOW,
    );
    const routineRow = buildLocalComplaintRow(
        { category: 'operational', title: 'Routine issue', description: 'Not urgent.', urgent: false },
        LOCAL_UUID,
        NOW,
    );

    assert.equal(urgentRow.urgent, 1);
    assert.equal(routineRow.urgent, 0);
});

test('a missing description is stored as null, not an empty string or undefined', () => {
    const row = buildLocalComplaintRow(
        { category: 'operational', title: 'No description given', urgent: false },
        LOCAL_UUID,
        NOW,
    );

    assert.equal(row.description, null);
});
