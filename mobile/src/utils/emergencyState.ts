/**
 * Pure decision logic for complaint-desk.md section 7's emergency
 * broadcast treatment — kept dependency-free and screen-free (same split
 * as src/utils/validation.ts) so the two load-bearing rules below are
 * unit-testable without expo-sqlite/expo-router:
 *
 *   1. Whether the full-screen interrupt (app/emergency.tsx) should fire
 *      on this app open — shouldTriggerEmergencyInterrupt().
 *   2. What the persistent banner (src/components/ui/EmergencyBanner.tsx)
 *      should show, including the "acted on but not yet confirmed"
 *      distinction that's the whole point of the ack_pending column
 *      (src/db/notifications.ts) — describeEmergencyBanner().
 */

/**
 * Gates app/_layout.tsx's RootNavigation one-time-per-app-open push to
 * '/emergency'. `needingInterruptCount` must come from
 * getEmergenciesNeedingInterrupt() (severity='emergency' AND
 * acknowledged_at IS NULL AND ack_pending = 0) — an emergency the agent
 * has already pressed Acknowledge on (even if that action is still queued
 * offline) must NOT re-trigger the interrupt; only the banner reflects it
 * from that point on.
 */
export function shouldTriggerEmergencyInterrupt(needingInterruptCount: number): boolean {
    return needingInterruptCount > 0;
}

export interface EmergencyBannerView {
    /** False when there is nothing unacknowledged — the banner renders
     * nothing at all in this case, adding zero visual weight to the
     * common case. */
    visible: boolean;
    /** True when at least one emergency still needs the agent to press
     * Acknowledge (tapping the banner opens app/emergency.tsx). False
     * when every remaining unacknowledged emergency is already queued
     * (ack_pending=1) awaiting server confirmation — the banner is then
     * informational only, not tappable into another interrupt. */
    needsAction: boolean;
    label: string | null;
}

/**
 * `unacknowledgedCount` — every severity='emergency' notification with
 * acknowledged_at still null (getUnacknowledgedEmergencies()).
 * `needingInterruptCount` — the narrower subset also awaiting a FIRST
 * acknowledge attempt (ack_pending=0). needingInterruptCount must never
 * exceed unacknowledgedCount by construction (it's always a subset), but
 * this function doesn't assume that invariant — it only ever compares
 * needingInterruptCount to zero, so a caller passing inconsistent counts
 * can't produce a nonsensical "needs action" reading either way.
 */
export function describeEmergencyBanner(unacknowledgedCount: number, needingInterruptCount: number): EmergencyBannerView {
    if (unacknowledgedCount <= 0) {
        return { visible: false, needsAction: false, label: null };
    }

    const needsAction = needingInterruptCount > 0;

    const label = needsAction
        ? `${unacknowledgedCount === 1 ? '1 emergency complaint needs' : `${unacknowledgedCount} emergency complaints need`} acknowledgement — tap to review`
        : "Acknowledging — confirming with the server once you're back online";

    return { visible: true, needsAction, label };
}
