/**
 * Renders a date string as "MMM YY" (e.g. "Dec 26") — the format the
 * manuscript register PDF uses for the Expiry column
 * (resources/views/pdf/manuscript.blade.php, Carbon ->format('M y')), so the
 * web Manuscripts page and the printed register agree.
 *
 * Accepts a plain date ("2026-12-29") or a full ISO datetime; formats in UTC
 * so it matches the server exactly regardless of the viewer's timezone.
 * Returns "—" for null/empty/unparseable input.
 */
export function formatMonthYear(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString('en-US', { month: 'short', year: '2-digit', timeZone: 'UTC' });
}
