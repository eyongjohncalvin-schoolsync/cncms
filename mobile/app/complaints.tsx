import { useCallback, useEffect, useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable, StyleSheet } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { EmptyState } from '../src/components/ui/EmptyState';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { getRecentComplaints } from '../src/db/complaints';
import { fetchComplaintStatuses, type RemoteComplaintStatus } from '../src/api/complaints';
import { subscribeSyncState } from '../src/sync/syncStore';
import { subscribeNotificationsState } from '../src/notifications/notificationStore';
import {
    buildComplaintListRows,
    COMPLAINT_STATUS_BADGE_TONE,
    COMPLAINT_STATUS_LABEL,
    type ComplaintListRow,
} from '../src/utils/complaintStatus';
import { formatShortDate } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget, shadow } from '../src/theme/tokens';
import type { LocalComplaint } from '../src/types/db';

const CATEGORY_LABEL: Record<ComplaintListRow['category'], string> = {
    operational: 'Operational',
    customer: 'Customer',
};

/**
 * "Complaints" — a read-only history of complaints THIS DEVICE has
 * submitted (complaint-desk.md §7), a sibling of History
 * (app/(tabs)/history.tsx) for the "Log a Complaint" submission flow
 * (app/log-complaint.tsx, untouched by this screen). NOT a queue for
 * resolving/assigning/reopening — those are super/admin/manager-only per
 * App\Policies\ComplaintPolicy, "never the submitter," so no such action
 * exists anywhere on this screen.
 *
 * Data-source note (important, read before changing this screen): unlike
 * payments, complaint sync is genuinely push/create-only —
 * App\Services\SyncService::pull() never returns complaints, confirmed by
 * reading it, and src/db/complaints.ts's local `complaints` table has no
 * status/resolution_notes column at all. So the base list below always
 * renders instantly from local SQLite (offline-first, exactly like every
 * other list in this app) showing this device's OWN submission + push
 * state (queued/syncing/synced/failed — the same amber "Saved · will
 * sync" vocabulary Record Payment/Expense/this form's own confirmation
 * screen already use). On top of that, `fetchComplaintStatuses()`
 * (src/api/complaints.ts) opportunistically calls the existing
 * GET /api/v1/complaints endpoint (ComplaintPolicy::viewAny() is open to
 * everyone) to learn the office's REAL status/resolution — best-effort,
 * silently degrades to an honest "Submitted" placeholder when offline or
 * before the first successful fetch, never guessed as "Open." This keeps
 * the screen literally offline-first (nothing above blocks on network)
 * while still answering "did the office resolve this?" once online.
 *
 * Live refresh: re-reads local SQLite AND re-attempts the live status
 * fetch on every sync-state change (`subscribeSyncState` — same trigger
 * History/Sync Status already use this session) and on every notification
 * arrival (`subscribeNotificationsState` — a complaint resolving broadcasts
 * a de-escalation notice per complaint-desk.md §5/§7, so this is often the
 * FIRST signal that something here changed), so a status flip shows up
 * while this screen is already open, not just on next visit.
 */
export default function ComplaintsScreen() {
    const router = useRouter();

    const [localComplaints, setLocalComplaints] = useState<LocalComplaint[]>([]);
    const [remoteByServerUuid, setRemoteByServerUuid] = useState<Map<string, RemoteComplaintStatus>>(new Map());
    const [loaded, setLoaded] = useState(false);

    const refreshLocal = useCallback(() => {
        void getRecentComplaints().then((locals) => {
            setLocalComplaints(locals);
            setLoaded(true);
        });
    }, []);

    const refreshRemote = useCallback(() => {
        // Best-effort only — see this screen's doc comment. A failure here
        // (offline, timeout, 401 mid-flight) must never surface as an error
        // state on this screen; rows just keep showing whatever was last
        // known (or the honest "Submitted" placeholder).
        void fetchComplaintStatuses()
            .then((remote) => setRemoteByServerUuid(new Map(remote.map((item) => [item.uuid, item] as const))))
            .catch(() => undefined);
    }, []);

    useFocusEffect(
        useCallback(() => {
            refreshLocal();
            refreshRemote();
        }, [refreshLocal, refreshRemote]),
    );

    useEffect(
        () =>
            subscribeSyncState(() => {
                refreshLocal();
                refreshRemote();
            }),
        [refreshLocal, refreshRemote],
    );

    useEffect(() => subscribeNotificationsState(() => refreshRemote()), [refreshRemote]);

    const rows = useMemo(() => buildComplaintListRows(localComplaints, remoteByServerUuid), [localComplaints, remoteByServerUuid]);
    const hasComplaints = rows.length > 0;

    if (loaded && !hasComplaints) {
        return (
            <View style={styles.flex}>
                <EmptyState
                    title="No complaints yet"
                    subtitle="Complaints you log will show up here, newest first, with their status once the office responds."
                    actionLabel="Log a complaint"
                    onAction={() => router.push('/log-complaint')}
                />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <FlatList
                data={rows}
                keyExtractor={(item) => item.localUuid}
                contentContainerStyle={styles.listContent}
                renderItem={({ item }) => <ComplaintRow row={item} />}
            />

            {/* Floating "+ New complaint" action — this screen's own way
                back into app/log-complaint.tsx, per complaint-desk.md §7's
                "one tap deeper" pattern (mirrors Home's "Log a complaint"
                secondary CTA rather than a 5th tab). A real circular FAB,
                not a shared primitive: Button.tsx has no icon-only/circular
                shape, and this screen doesn't own that file. */}
            <Pressable
                accessibilityRole="button"
                accessibilityLabel="Log a new complaint"
                onPress={() => router.push('/log-complaint')}
                style={({ pressed }) => [styles.fab, pressed && styles.fabPressed]}
            >
                <Text style={styles.fabIcon}>+</Text>
            </Pressable>
        </View>
    );
}

function statusBadge(row: ComplaintListRow): { tone: BadgeTone; label: string } {
    if (row.syncStatus === 'failed') {
        return { tone: 'error', label: "Couldn't send yet" };
    }

    if (row.syncStatus === 'queued' || row.syncStatus === 'syncing') {
        return { tone: 'offline', label: 'Saved · will sync' };
    }

    // synced from here down
    if (row.lifecycleStatus) {
        return { tone: COMPLAINT_STATUS_BADGE_TONE[row.lifecycleStatus], label: COMPLAINT_STATUS_LABEL[row.lifecycleStatus] };
    }

    // Synced to the server, but this device doesn't know the office's real
    // status yet (offline right now, or the live fetch hasn't landed) —
    // deliberately a different label from "Saved · will sync" (which means
    // something categorically different: not yet on the server at all).
    return { tone: 'neutral', label: 'Submitted' };
}

function ComplaintRow({ row }: { row: ComplaintListRow }) {
    const badge = statusBadge(row);
    const resolved = row.lifecycleStatus === 'resolved' && !!row.resolutionNotes;

    return (
        <View style={styles.row}>
            <View style={styles.rowTop}>
                <View style={styles.rowMain}>
                    <View style={styles.tagRow}>
                        <Text style={styles.categoryTag}>{CATEGORY_LABEL[row.category]}</Text>
                        {row.urgent ? <Text style={styles.urgentTag}>Urgent</Text> : null}
                    </View>
                    <Text style={styles.title} numberOfLines={2}>
                        {row.title}
                    </Text>
                    <Text style={styles.rowMeta}>{formatShortDate(row.createdAt)}</Text>
                </View>
                <Badge label={badge.label} tone={badge.tone} />
            </View>
            {resolved ? (
                <View style={styles.resolutionBox}>
                    <Text style={styles.resolutionLabel}>Resolution from the office</Text>
                    <Text style={styles.resolutionText}>{row.resolutionNotes}</Text>
                </View>
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    listContent: { padding: spacing.lg, paddingBottom: spacing.xxl + touchTarget.primary, gap: spacing.sm },
    row: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.lg,
        padding: spacing.md,
        backgroundColor: colors.surface,
        ...shadow.card,
    },
    rowTop: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.md },
    rowMain: { flex: 1, gap: 2 },
    tagRow: { flexDirection: 'row', gap: spacing.xs, marginBottom: 2 },
    categoryTag: {
        fontSize: fontSize.xs,
        fontWeight: '800',
        color: colors.accent.complaint,
        textTransform: 'uppercase',
        letterSpacing: 0.5,
    },
    urgentTag: {
        fontSize: fontSize.xs,
        fontWeight: '800',
        color: colors.danger,
        textTransform: 'uppercase',
        letterSpacing: 0.5,
    },
    title: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    rowMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
    resolutionBox: {
        marginTop: spacing.sm,
        paddingTop: spacing.sm,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        gap: 2,
    },
    resolutionLabel: { fontSize: fontSize.xs, fontWeight: '700', color: colors.status.verifiedFg },
    resolutionText: { fontSize: fontSize.sm, color: colors.textPrimary },
    fab: {
        position: 'absolute',
        right: spacing.lg,
        bottom: spacing.lg,
        width: touchTarget.primary,
        height: touchTarget.primary,
        borderRadius: touchTarget.primary / 2,
        backgroundColor: colors.accent.complaint,
        alignItems: 'center',
        justifyContent: 'center',
        ...shadow.hero,
    },
    fabPressed: { opacity: 0.9, transform: [{ scale: 0.97 }] },
    fabIcon: { fontSize: 28, fontWeight: '700', color: colors.textInverse, lineHeight: 30 },
});
