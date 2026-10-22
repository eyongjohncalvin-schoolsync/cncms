import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeMaintenanceBanner, formatRemaining, isAnnouncingEndpoint } from '../maintenanceState';

const window = { startsAt: '2026-10-22T21:00:00Z', endsAt: '2026-10-23T01:00:00Z' };

test('no window renders nothing', () => {
    assert.deepEqual(describeMaintenanceBanner(null, Date.now()), { visible: false, active: false, label: null });
});

test('a scheduled window shows an advance notice with the time left', () => {
    const view = describeMaintenanceBanner(window, Date.parse('2026-10-22T19:30:00Z'));

    assert.equal(view.visible, true);
    assert.equal(view.active, false);
    assert.match(view.label ?? '', /^Maintenance in 1h 30m/);
});

test('a running window is active and counts down to the end', () => {
    const view = describeMaintenanceBanner(window, Date.parse('2026-10-22T23:00:00Z'));

    assert.equal(view.active, true);
    assert.match(view.label ?? '', /2h 00m left/);
});

test('a finished window disappears without waiting for the server', () => {
    assert.equal(describeMaintenanceBanner(window, Date.parse('2026-10-23T01:00:00Z')).visible, false);
});

test('garbage timestamps never render a banner', () => {
    assert.equal(describeMaintenanceBanner({ startsAt: 'nope', endsAt: 'nope' }, Date.now()).visible, false);
});

test('formatRemaining covers minutes, hours and days', () => {
    assert.equal(formatRemaining(30 * 1000), 'under a minute');
    assert.equal(formatRemaining(12 * 60000), '12m');
    assert.equal(formatRemaining(125 * 60000), '2h 05m');
    assert.equal(formatRemaining((26 * 60 + 5) * 60000), '1d 2h');
});

test('only tenant endpoints announce maintenance', () => {
    assert.equal(isAnnouncingEndpoint('auth/me'), true);
    assert.equal(isAnnouncingEndpoint('/sync/pull?since=1'), true);
    assert.equal(isAnnouncingEndpoint('auth/login'), false);
    assert.equal(isAnnouncingEndpoint('/auth/logout'), false);
    assert.equal(isAnnouncingEndpoint(undefined), false);
});
