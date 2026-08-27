/**
 * "Send Bill via WhatsApp" (manual mode — see
 * .claude/skills/cncms-context/references/bill-notifications.md §1 and
 * App\Services\BillNotificationService.php, the backend twin of this
 * logic). The backend endpoint (GET /api/v1/bills/{uuid}/whatsapp-message)
 * deliberately returns only the two raw ingredients — a normalized phone
 * number and a composed message — NOT a ready-made wa.me link; this module
 * is where the mobile client builds that link itself, per the feature spec.
 *
 * normalizeCameroonPhoneForWhatsapp() mirrors
 * BillNotificationService::normalizePhoneForWhatsapp() on the server. It is
 * NOT the source of truth for what's sent (the server-normalized `phone`
 * from the API response is) — it exists so this module can validate a
 * phone defensively before building a link, and so its edge cases are unit
 * testable on the client without a running backend.
 */

/**
 * Normalizes a raw phone string into the digits-only, '237'-prefixed form
 * wa.me requires (no leading '+' or '00'). Cameroon mobile numbers are 9
 * local digits. Returns null rather than guessing when the shape doesn't
 * match — per bill-notifications.md §5, a wrong number is worse than no
 * link, and ~78% of legacy customers have no phone on file at all (see
 * mobile-app-react-native.md and database-schema.md's known data-quality
 * issues), so null is an expected, common, non-error outcome here.
 */
export function normalizeCameroonPhoneForWhatsapp(phone: string | null | undefined): string | null {
    if (!phone || phone.trim() === '') {
        return null;
    }

    let digits = phone.replace(/\D+/g, '');

    if (digits === '') {
        return null;
    }

    if (digits.startsWith('237') && digits.length === 12) {
        return digits;
    }

    if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }

    return digits.length === 9 ? `237${digits}` : null;
}

/**
 * Builds the full https://wa.me/{phone}?text={message} deep link that
 * Linking.openURL() hands off to the native WhatsApp app. `phone` is
 * expected to already be in normalized international form (as returned by
 * the API, or by normalizeCameroonPhoneForWhatsapp() above) — this
 * function does not re-normalize it, so a caller passing a raw/unnormalized
 * phone will produce a broken link; validate first.
 *
 * Returns null when either ingredient is missing, mirroring the backend's
 * `available: false` case — callers should treat null the same way they'd
 * treat the API reporting `available: false` (show an explanatory state,
 * never attempt Linking.openURL(null)).
 */
export function buildWhatsAppBillLink(phone: string | null | undefined, message: string | null | undefined): string | null {
    if (!phone || !message) {
        return null;
    }

    return `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
}
