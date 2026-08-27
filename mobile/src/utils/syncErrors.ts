/**
 * Rephrases sync_error strings for the "Needs attention" section of the
 * Sync Status detail sheet, per mobile-app-react-native.md §6's
 * "plain-language errors, always with a next step" rule.
 *
 * What SyncManager actually stores in `sync_error` (verified against
 * app/Services/SyncService.php's errorMessage(), not assumed): a
 * ValidationException is flattened into readable sentences like "The
 * customer uuid field is required." — already plain enough to show as-is.
 * Anything else falls through to `$e->getMessage()` on a raw Throwable,
 * which can be arbitrary technical PHP/DB text (e.g. an Eloquent
 * "SQLSTATE[23505]..." or "Call to a member function on null" message) —
 * exactly what the design doc says never to surface verbatim.
 */
const TECHNICAL_MARKERS = [
    'SQLSTATE',
    'Exception',
    'Call to a member',
    'Call to undefined',
    'Undefined array key',
    'Undefined property',
    'Trying to access',
    'stack trace',
    'Illuminate\\',
    'App\\',
    'Symfony\\',
];

const FALLBACK_MESSAGE =
    "The server couldn't accept this item. It's still safely saved on this device — try syncing again, or contact the office if this keeps happening.";

const EMPTY_MESSAGE = "Couldn't save this on the server. It's still safely stored on this device — try syncing again.";

export function humanizeSyncError(raw: string | null | undefined): string {
    if (!raw || !raw.trim()) {
        return EMPTY_MESSAGE;
    }

    const looksTechnical = TECHNICAL_MARKERS.some((marker) => raw.includes(marker)) || raw.length > 160;

    return looksTechnical ? FALLBACK_MESSAGE : raw;
}
