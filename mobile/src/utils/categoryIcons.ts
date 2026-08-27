/**
 * `expense_categories.icon` (server) stores a Tabler Icons CSS class used by
 * the web admin's icon font — e.g. "ti-users", "ti-truck" — confirmed via
 * database/seeders/TenantDatabaseSeeder.php (the 9 seeded categories) and
 * database/migrations/tenant/..._create_expense_categories_table.php
 * (`icon` is a plain nullable string(30), not an emoji or a bundled asset).
 * This RN app ships no icon-font/vector-icon dependency (mobile-app-
 * react-native.md §6: "no full component library", build primitives by
 * hand), so rendering the raw class name as literal text would just show
 * "ti-users" next to the category name, which isn't the "visually
 * scannable" list the brief asks for.
 *
 * Rather than adding an icon-font dependency for nine categories, this maps
 * the known seeded slugs to a plain glyph and falls back to the category's
 * first letter — the same circle-letter pattern already used for the tab
 * bar (see TabGlyph in app/(tabs)/_layout.tsx) — for anything unrecognized,
 * e.g. an admin-added category with a new slug.
 */
const KNOWN_ICON_GLYPHS: Record<string, string> = {
    'ti-users': '👥',
    'ti-truck': '🚚',
    'ti-tool': '🔧',
    'ti-building': '🏢',
    'ti-bolt': '⚡',
    'ti-credit-card': '💳',
    'ti-antenna': '📡',
    'ti-device-tv': '📺',
    'ti-dots': '⋯',
};

export function glyphForCategoryIcon(icon: string | null | undefined, name: string): string {
    if (icon && KNOWN_ICON_GLYPHS[icon]) {
        return KNOWN_ICON_GLYPHS[icon];
    }

    const initial = name.trim().charAt(0).toUpperCase();

    return initial || '?';
}
