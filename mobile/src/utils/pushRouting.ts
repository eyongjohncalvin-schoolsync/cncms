/**
 * Pure decision logic for where a tapped (or cold-launched-via-tap) push
 * notification should navigate — kept dependency-free and screen-free (same
 * split as src/utils/emergencyState.ts) so this is unit-testable without
 * expo-notifications/expo-router.
 *
 * The destinations are app/emergency.tsx, app/notifications.tsx and the
 * manager inbox tab (app/(tabs)/inbox.tsx), and one complaint
 * (app/complaint/[uuid].tsx) when a complaint alert names it by
 * `source_type`/`source_uuid` — the technician's "new job" ring.
 *
 * Critically: resolving a tap target is NOT the same as acknowledging an
 * emergency. Landing on app/emergency.tsx via a tap still requires the
 * agent to press the in-screen "Acknowledge" button — see that screen's
 * class doc and src/utils/emergencyState.ts's doc comment for why a push
 * (tapped or merely received) is treated as strictly weaker evidence than
 * "acknowledged," even weaker than "read." This function only ever decides
 * WHERE to navigate, never calls any acknowledge/read state mutation.
 */
export type PushTapTarget = '/emergency' | '/notifications' | '/inbox' | `/complaint/${string}`;

export interface PushTapData {
    /** The `data.severity` field App\Jobs\SendPushNotificationJob puts on
     * every Expo push payload — see that job's class doc. Untyped/optional
     * here since it arrives over the wire as arbitrary JSON. */
    severity?: string;
    /** The notification `type` on the same payload, e.g. `inbox.voucher`. */
    type?: string;
    /** What the alert is about, e.g. `complaint` + its uuid (wave 3). */
    source_type?: string | null;
    source_uuid?: string | null;
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

    // A technician's new job (or any other complaint alert naming one) opens that complaint.
    if (typeof data.type === 'string' && data.type.startsWith('complaint.') && data.source_type === 'complaint' && typeof data.source_uuid === 'string' && data.source_uuid !== '') {
        return `/complaint/${data.source_uuid}`;
    }

    return '/notifications';
}
