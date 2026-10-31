/**
 * Pure decision logic for where a tapped (or cold-launched-via-tap) push
 * notification should navigate — kept dependency-free and screen-free (same
 * split as src/utils/emergencyState.ts) so this is unit-testable without
 * expo-notifications/expo-router.
 *
 * The destinations are app/emergency.tsx, app/notifications.tsx and the
 * manager inbox tab (app/(tabs)/inbox.tsx) — there is no per-complaint
 * detail screen on mobile (no
 * `/complaints/[uuid]` route), so this deliberately never tries to route
 * anywhere more specific than that, regardless of what `link`/`source_type`
 * the push payload carries.
 *
 * Critically: resolving a tap target is NOT the same as acknowledging an
 * emergency. Landing on app/emergency.tsx via a tap still requires the
 * agent to press the in-screen "Acknowledge" button — see that screen's
 * class doc and src/utils/emergencyState.ts's doc comment for why a push
 * (tapped or merely received) is treated as strictly weaker evidence than
 * "acknowledged," even weaker than "read." This function only ever decides
 * WHERE to navigate, never calls any acknowledge/read state mutation.
 */
export type PushTapTarget = '/emergency' | '/notifications' | '/inbox';

export interface PushTapData {
    /** The `data.severity` field App\Jobs\SendPushNotificationJob puts on
     * every Expo push payload — see that job's class doc. Untyped/optional
     * here since it arrives over the wire as arbitrary JSON. */
    severity?: string;
    /** The notification `type` on the same payload, e.g. `inbox.voucher`. */
    type?: string;
}

/** Alerts about something to decide (docs/plans/mobile-manager-app.md, wave 2). */
const INBOX_TYPES = ['voucher.stakeholder_approval'];

export function resolvePushTapTarget(data: PushTapData): PushTapTarget {
    if (data.severity === 'emergency') {
        return '/emergency';
    }

    if (typeof data.type === 'string' && (data.type.startsWith('inbox.') || INBOX_TYPES.includes(data.type))) {
        return '/inbox';
    }

    return '/notifications';
}
