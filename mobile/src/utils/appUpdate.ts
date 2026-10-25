/**
 * "New version available" (src/components/ui/AppUpdateBanner.tsx). The
 * server names the current APK on every API response (X-App-Latest-Version,
 * X-App-Download-Url — config/agent-app.php); the banner shows when the
 * installed build is older. Over-the-air updates never change the installed
 * version, so this only ever asks for a new APK when one is really needed.
 */
export interface AppUpdateInfo {
    latestVersion: string;
    downloadUrl: string;
}

/** "1.0.10" vs "1.0.9" → 1; missing or non-numeric parts count as 0. */
export function compareVersions(a: string, b: string): number {
    const left = a.split('.').map((part) => Number.parseInt(part, 10) || 0);
    const right = b.split('.').map((part) => Number.parseInt(part, 10) || 0);

    for (let i = 0; i < Math.max(left.length, right.length); i++) {
        const difference = (left[i] ?? 0) - (right[i] ?? 0);

        if (difference !== 0) {
            return difference > 0 ? 1 : -1;
        }
    }

    return 0;
}

/** Only an http(s) link the server sent, and only when the installed build is behind. */
export function needsUpdate(installedVersion: string | null, info: AppUpdateInfo | null): boolean {
    if (!installedVersion || !info || !/^https?:\/\//i.test(info.downloadUrl)) {
        return false;
    }

    return compareVersions(installedVersion, info.latestVersion) < 0;
}
