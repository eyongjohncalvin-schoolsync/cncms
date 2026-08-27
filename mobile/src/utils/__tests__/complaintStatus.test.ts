import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildComplaintListRow } from '../complaintStatus';
import type { LocalComplaint } from '../../types/db';
import type { RemoteComplaintStatus } from '../../api/complaints';

const NOW = '2026-08-27T09:00:00.000Z';

function localComplaint(overrides: Partial<LocalComplaint> = {}): LocalComplaint {
    return {
        local_uuid: 'local-1',
        server_uuid: null,
        category: 'operational',
        title: "Zone 3 list won't sync",
        description: 'Happens every morning.',
        urgent: 0,
        customer_uuid: null,
        sync_status: 'queued',
        sync_error: null,
        sync_attempts: 0,
        created_at: NOW,
        updated_at: NOW,
        ...overrides,
    };
}

test('a not-yet-synced complaint has no lifecycle status, never guessed as open', () => {
    const row = buildComplaintListRow(localComplaint({ sync_status: 'queued' }), new Map());

    assert.equal(row.lifecycleStatus, null, 'must not be guessed — this device genuinely does not know yet');
    assert.equal(row.resolutionNotes, null);
    assert.equal(row.syncStatus, 'queued');
});

test('a synced complaint with no matching live status yet stays unknown, not defaulted', () => {
    const row = buildComplaintListRow(localComplaint({ sync_status: 'synced', server_uuid: 'server-1' }), new Map());

    assert.equal(row.lifecycleStatus, null, 'no fetchComplaintStatuses() result yet — must stay unknown, not "open"');
});

test('a synced complaint is enriched with the matching live status by server_uuid', () => {
    const remote: RemoteComplaintStatus = {
        uuid: 'server-1',
        status: 'resolved',
        resolution_notes: 'Fixed the sync worker; re-ran the zone import.',
        resolved_at: '2026-08-26T10:00:00.000Z',
    };
    const remoteByServerUuid = new Map([[remote.uuid, remote]]);

    const row = buildComplaintListRow(localComplaint({ sync_status: 'synced', server_uuid: 'server-1' }), remoteByServerUuid);

    assert.equal(row.lifecycleStatus, 'resolved');
    assert.equal(row.resolutionNotes, 'Fixed the sync worker; re-ran the zone import.');
});

test('a live status for a DIFFERENT complaint never leaks onto an unrelated local row', () => {
    const remote: RemoteComplaintStatus = {
        uuid: 'some-other-server-uuid',
        status: 'resolved',
        resolution_notes: 'Unrelated.',
        resolved_at: '2026-08-26T10:00:00.000Z',
    };
    const remoteByServerUuid = new Map([[remote.uuid, remote]]);

    const row = buildComplaintListRow(localComplaint({ sync_status: 'synced', server_uuid: 'server-1' }), remoteByServerUuid);

    assert.equal(row.lifecycleStatus, null);
    assert.equal(row.resolutionNotes, null);
});

test('urgent 0/1 maps to a real boolean on the view row', () => {
    const urgentRow = buildComplaintListRow(localComplaint({ urgent: 1 }), new Map());
    const routineRow = buildComplaintListRow(localComplaint({ urgent: 0 }), new Map());

    assert.equal(urgentRow.urgent, true);
    assert.equal(routineRow.urgent, false);
});
