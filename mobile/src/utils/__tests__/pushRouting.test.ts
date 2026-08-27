import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolvePushTapTarget } from '../pushRouting';

test('an emergency-severity push routes to the full-screen interrupt', () => {
    assert.equal(resolvePushTapTarget({ severity: 'emergency' }), '/emergency');
});

test('an urgent-severity push routes to the routine notifications list', () => {
    assert.equal(resolvePushTapTarget({ severity: 'urgent' }), '/notifications');
});

test('info/warning severities (never actually pushed server-side, but defensively handled) route to the routine list', () => {
    assert.equal(resolvePushTapTarget({ severity: 'info' }), '/notifications');
    assert.equal(resolvePushTapTarget({ severity: 'warning' }), '/notifications');
});

test('a missing or malformed severity safely defaults to the non-blocking routine list, never the interrupt', () => {
    assert.equal(resolvePushTapTarget({}), '/notifications');
    assert.equal(resolvePushTapTarget({ severity: undefined }), '/notifications');
    assert.equal(resolvePushTapTarget({ severity: 'garbage' }), '/notifications');
});
