import { useSyncExternalStore } from 'react';
import { Pressable, View, Text, StyleSheet } from 'react-native';
import { useRouter } from 'expo-router';
import { getSyncState, subscribeSyncState, type SyncPhase } from '../../sync/syncStore';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * The single most load-bearing UI element in the app — mobile-app-react-native.md
 * §5. Persistent, visible on every screen (mounted once in the tabs shell
 * layout, not per-screen), ~28-32dp. Four states, deliberately different in
 * TONE, not just color: offline/queuing must never look like an error —
 * conflating "expected" with "something is wrong" erodes agent trust fast
 * (see the design doc's rationale). Tapping opens the "Sync Status" detail
 * sheet (app/sync-status.tsx) — a modal, not a tab, per §4's explicit "Sync
 * Status is not a tab" decision — which itself hosts the manual "Sync Now"
 * trigger and the per-item pending/failed list.
 */
export function SyncStatusStrip() {
    const state = useSyncExternalStore(subscribeSyncState, getSyncState);
    const router = useRouter();

    const visual = visualFor(state.phase, state);

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${visual.label}. Tap for sync details.`}
            onPress={() => router.push('/sync-status')}
            style={[styles.strip, { backgroundColor: visual.bg }]}
        >
            {visual.showDot && <View style={[styles.dot, { backgroundColor: visual.dot }]} />}
            {visual.glyph ? <Text style={[styles.glyph, { color: visual.fg }]}>{visual.glyph}</Text> : null}
            <Text style={[styles.label, { color: visual.fg }]} numberOfLines={1}>
                {visual.label}
            </Text>
        </Pressable>
    );
}

interface Visual {
    bg: string;
    fg: string;
    dot: string;
    showDot: boolean;
    glyph: string | null;
    label: string;
}

function visualFor(phase: SyncPhase, state: ReturnType<typeof getSyncState>): Visual {
    switch (phase) {
        case 'syncing': {
            const progress = state.syncingProgress;
            const label = progress ? `Syncing ${progress.done} of ${progress.total}…` : 'Syncing…';

            return { bg: colors.status.syncingBg, fg: colors.status.syncingFg, dot: colors.status.syncingDot, showDot: true, glyph: null, label };
        }
        case 'offline': {
            const label =
                state.queuedCount > 0
                    ? `Offline — ${state.queuedCount} item${state.queuedCount === 1 ? '' : 's'} saved, will sync when connected`
                    : 'Offline — will sync when connected';

            return { bg: colors.status.offlineBg, fg: colors.status.offlineFg, dot: colors.status.offlineDot, showDot: false, glyph: '☁', label };
        }
        case 'error': {
            const label = `Couldn't sync ${state.failedCount} item${state.failedCount === 1 ? '' : 's'} — tap to retry`;

            return { bg: colors.status.errorBg, fg: colors.status.errorFg, dot: colors.status.errorDot, showDot: false, glyph: '!', label };
        }
        case 'synced':
        default:
            return { bg: colors.background, fg: colors.textSecondary, dot: colors.status.syncedDot, showDot: true, glyph: null, label: 'Synced' };
    }
}

const styles = StyleSheet.create({
    strip: {
        flexDirection: 'row',
        alignItems: 'center',
        minHeight: 30,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        gap: spacing.xs,
    },
    dot: {
        width: 8,
        height: 8,
        borderRadius: 4,
    },
    glyph: {
        fontSize: fontSize.sm,
        fontWeight: '700',
    },
    label: {
        fontSize: fontSize.xs,
        fontWeight: '600',
        flexShrink: 1,
    },
});
