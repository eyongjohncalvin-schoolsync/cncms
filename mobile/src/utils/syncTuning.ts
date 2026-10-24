/**
 * Pure knobs for syncing over slow mobile data (MTN/Orange 3G), kept free of
 * React Native imports so they are unit-tested under plain Node.
 */

/** Per-endpoint request timeouts. A full pull or a photo upload on 3G can take well over the old blanket 20 s. */
export const REQUEST_TIMEOUT_MS = {
    default: 30_000,
    sync: 90_000,
    upload: 120_000,
} as const;

/** Receipt photos are resized so the long edge is at most this many pixels before they are queued. */
export const RECEIPT_PHOTO_MAX_EDGE = 1280;

/** JPEG quality for a resized receipt photo — roughly 150–300 KB at 1280 px. */
export const RECEIPT_PHOTO_COMPRESS = 0.6;

/**
 * The resize to apply to a `width` x `height` photo so its long edge fits
 * `maxEdge`, preserving the aspect ratio (only one side is given; the
 * manipulator derives the other). Null when it already fits, or when the
 * dimensions are unknown.
 */
export function receiptPhotoResize(width: number, height: number, maxEdge: number = RECEIPT_PHOTO_MAX_EDGE): { width: number } | { height: number } | null {
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
        return null;
    }

    if (Math.max(width, height) <= maxEdge) {
        return null;
    }

    return width >= height ? { width: maxEdge } : { height: maxEdge };
}

/**
 * A retry delay spread by ±`spread` (20% by default) so a fleet of phones
 * that lost the network together does not hammer the server in lockstep
 * when it comes back.
 */
export function withJitter(baseMs: number, random: () => number = Math.random, spread = 0.2): number {
    const factor = 1 - spread + random() * spread * 2;

    return Math.round(baseMs * factor);
}

/**
 * Whether a failed receipt-photo upload will never succeed by retrying the
 * same file: 413 (too large for the web server) or 422 (rejected by
 * validation, e.g. over the size limit). Timeouts, network errors (no
 * status) and 5xx stay retryable; 403 is left to the caller.
 */
export function isFinalUploadFailure(status: number | null | undefined): boolean {
    return status === 413 || status === 422;
}
