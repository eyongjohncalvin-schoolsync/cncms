import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeEmergencyBanner, shouldTriggerEmergencyInterrupt } from '../emergencyState';

test('shouldTriggerEmergencyInterrupt is false when nothing needs a first acknowledge attempt', () => {
    assert.equal(shouldTriggerEmergencyInterrupt(0), false);
});

test('shouldTriggerEmergencyInterrupt is true when at least one emergency is untouched', () => {
    assert.equal(shouldTriggerEmergencyInterrupt(1), true);
    assert.equal(shouldTriggerEmergencyInterrupt(3), true);
});

test('the banner renders nothing when there is no unacknowledged emergency at all', () => {
    const view = describeEmergencyBanner(0, 0);

    assert.equal(view.visible, false);
    assert.equal(view.needsAction, false);
    assert.equal(view.label, null);
});

test('the banner is actionable and tappable when at least one emergency still needs a first acknowledge', () => {
    const view = describeEmergencyBanner(2, 1);

    assert.equal(view.visible, true);
    assert.equal(view.needsAction, true);
    assert.match(view.label ?? '', /2 emergency complaints need/);
});

test('the banner singularizes the count for exactly one unacknowledged emergency', () => {
    const view = describeEmergencyBanner(1, 1);

    assert.equal(view.needsAction, true);
    assert.match(view.label ?? '', /^1 emergency complaint needs/);
});

/**
 * The distinction this whole module exists for (complaint-desk.md section
 * 7's "queue and confirm once connectivity returns" requirement): every
 * remaining unacknowledged emergency has already had Acknowledge pressed
 * (ack_pending=1, so needingInterruptCount=0) but the server hasn't
 * confirmed yet — the banner must stay visible with different, non-
 * alarming-but-not-silent copy, and must NOT be presented as tappable
 * into another interrupt (the agent already acted).
 */
test('the banner shows a distinct non-actionable "confirming" state when everything remaining is already queued', () => {
    const view = describeEmergencyBanner(2, 0);

    assert.equal(view.visible, true);
    assert.equal(view.needsAction, false, 'must not invite another tap into the full-screen interrupt');
    assert.match(view.label ?? '', /confirming/i);
    assert.doesNotMatch(view.label ?? '', /tap to review/);
});

test('needingInterruptCount never exceeding unacknowledgedCount is not assumed — a defensive zero-unacknowledged case still hides the banner', () => {
    // Pathological input (should never happen in practice — the interrupt
    // set is always a subset of the unacknowledged set) still resolves to
    // "nothing to show" rather than a nonsensical actionable banner.
    const view = describeEmergencyBanner(0, 5);

    assert.equal(view.visible, false);
});
