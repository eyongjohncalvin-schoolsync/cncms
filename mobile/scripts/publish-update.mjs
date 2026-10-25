// Publishes an over-the-air update to the agents' phones (expo-updates).
//
//   npm run publish:update -- "Fix the payments list on small screens"
//
// `eas update` does not read the build profile's `env` from eas.json, and the
// API address is baked into the bundle at publish time — an update published
// without it would point every phone at the development fallback in
// src/api/config.ts and stop all syncing. This script always sets it.
//
// Only JS/TS changes travel this way. Native changes (a new module, a
// permission, an Expo upgrade) need a new APK: the fingerprint runtime
// version keeps such an update from ever landing on an older build.
import { spawnSync } from 'node:child_process';

const API_BASE_URL = 'https://cncms.tech/api/v1';
const CHANNEL = 'preview';

const message = process.argv.slice(2).join(' ').trim();

if (message === '') {
    console.error('Say what changed: npm run publish:update -- "Fix the payments list on small screens"');
    process.exit(1);
}

// On Windows npx is a .cmd and needs a shell, which would split the message
// on spaces — so it goes quoted there.
const onWindows = process.platform === 'win32';
const messageArg = onWindows ? `"${message.replace(/"/g, "'")}"` : message;

// Android only: agents' builds are Android APKs (no iOS or web build exists).
const result = spawnSync('npx', ['eas-cli', 'update', '--channel', CHANNEL, '--environment', CHANNEL, '--platform', 'android', '--message', messageArg, '--non-interactive'], {
    stdio: 'inherit',
    shell: onWindows,
    env: { ...process.env, EXPO_PUBLIC_API_BASE_URL: API_BASE_URL },
});

process.exit(result.status ?? 1);
