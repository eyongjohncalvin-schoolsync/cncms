import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deskStatus } from '../deskStatus';

test('open and started complaints read as such', () => {
    assert.deepEqual(deskStatus({ status: 'open', escalated_at: null }), { label: 'Open', tone: 'pending' });
    assert.deepEqual(deskStatus({ status: 'in_progress', escalated_at: null }), { label: 'Started', tone: 'syncing' });
});

test('an escalated complaint shows as escalated even once started', () => {
    assert.equal(deskStatus({ status: 'in_progress', escalated_at: '2026-11-01T08:00:00Z' }).label, 'Escalated');
});

test('a resolved complaint shows as resolved even if it had escalated', () => {
    assert.deepEqual(deskStatus({ status: 'resolved', escalated_at: '2026-11-01T08:00:00Z' }), { label: 'Resolved', tone: 'verified' });
});
