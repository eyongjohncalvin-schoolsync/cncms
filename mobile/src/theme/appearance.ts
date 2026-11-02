import * as SecureStore from 'expo-secure-store';

/**
 * Light or dark, chosen in the app (owner, 2026-11-02): "Match my phone"
 * (the default), or always light, or always dark. Kept on this phone — the
 * website has its own switch, since a person may want the office screen
 * light and the phone dark. Read synchronously when the app opens
 * (src/theme/colors.ts picks the palette once), so a change applies after
 * the app restarts.
 */
export type AppearanceChoice = 'system' | 'light' | 'dark';

const KEY = 'cncms_appearance';

export function getAppearanceChoice(): AppearanceChoice {
    try {
        const stored = SecureStore.getItem(KEY);

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

export function saveAppearanceChoice(choice: AppearanceChoice): void {
    SecureStore.setItem(KEY, choice);
}
