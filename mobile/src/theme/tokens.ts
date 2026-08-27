/**
 * Spacing/sizing tokens. Touch targets per mobile-app-react-native.md §6:
 * 56dp for primary actions, 48dp floor for everything else.
 */
export const spacing = {
    xs: 4,
    sm: 8,
    md: 12,
    lg: 16,
    xl: 24,
    xxl: 32,
} as const;

export const radius = {
    sm: 6,
    md: 10,
    lg: 14,
    pill: 999,
} as const;

export const touchTarget = {
    primary: 56,
    floor: 48,
} as const;

export const fontSize = {
    xs: 12,
    sm: 14,
    md: 16,
    lg: 18,
    xl: 22,
    xxl: 28,
    display: 36,
} as const;
