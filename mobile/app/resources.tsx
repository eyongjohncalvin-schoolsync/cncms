import { useCallback, useEffect, useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable, StyleSheet } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { Button } from '../src/components/ui/Button';
import { EmptyState } from '../src/components/ui/EmptyState';
import { FlagChips } from '../src/components/expenses/FlagChips';
import { getRecentExpenditures, getExpenditureTotalSince } from '../src/db/expenditures';
import { getExpenseCategories } from '../src/db/categories';
import { getCachedExpenseStatuses } from '../src/db/expenseReference';
import { syncManager } from '../src/sync/SyncManager';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { expenseStatusDisplay } from '../src/utils/expenseControls';
import type { ExpenditureApi } from '../src/types/api';
import { glyphForCategoryIcon } from '../src/utils/categoryIcons';
import {
    EXPENDITURE_PERIODS,
    filterExpendituresByCategory,
    periodLabel,
    periodStartDate,
    type ExpenditurePeriod,
} from '../src/utils/expenditureFilters';
import { formatFcfa, formatShortDate } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { LocalExpenditure, LocalExpenseCategory } from '../src/types/db';

const SYNC_BADGE: Record<LocalExpenditure['sync_status'], { label: string; tone: BadgeTone }> = {
    queued: { label: 'Saved · will sync', tone: 'offline' },
    syncing: { label: 'Syncing…', tone: 'syncing' },
    synced: { label: 'Synced', tone: 'synced' },
    failed: { label: 'Sync failed', tone: 'error' },
};

/** Server status when known (online, or the last cached copy), else the
 * local outbox state — so an unsynced expense still reads calmly. */
function expenseRowBadge(
    item: LocalExpenditure,
    serverRows: Record<string, ExpenditureApi>,
): { label: string; tone: BadgeTone } {
    const server = item.server_uuid ? serverRows[item.server_uuid] : undefined;
    const display = expenseStatusDisplay(server?.status);

    return display ?? SYNC_BADGE[item.sync_status];
}

/**
 * Resources — this device's own recorded-expenditures history, a sibling of
 * History ("My Recorded Payments") for the expense side of the same
 * offline-first outbox pattern (src/db/expenditures.ts), newest first, with
 * the same sync-status badge vocabulary used everywhere else in this app
 * (queued='Saved · will sync' amber, synced=green — never red for normal
 * offline operation, per mobile-app-react-native.md §5).
 *
 * Deliberately NOT the company P&L / budget-vs-actual dashboard the web
 * admin's resources/tsx/pages/Resources/Dashboard.tsx renders:
 * ExpenditurePolicy::viewDashboard() is super/admin/manager only,
 * explicitly excluding 'agent' — see that policy's own doc comment
 * ("Viewing the P&L dashboard itself is a step up from viewing the raw
 * list"). This screen only ever renders viewAny()-level data (open to
 * everyone) plus a local SUM of this device's own rows via
 * getExpenditureTotalSince() — never a call to the office-only dashboard
 * endpoint. No charts anywhere (mobile-app-react-native.md §6): the period
 * total is a single plain numeral in a hero card, the one and only
 * "dashboard-ish" element on this screen, matching Home's "Today's
 * collection" hero card's own restraint.
 *
 * Expense controls (2026-10): each row shows the SERVER status (Approved /
 * Waiting for receipt / Waiting approval / Owner approval / Rejected / Void)
 * plus its control flags, from GET /resources/expenditures?mine=1 when
 * online — cached in sync_meta so the last known status still shows
 * offline; an expense that hasn't synced yet keeps its calm local badge.
 * Tapping a row opens app/expense/[localUuid].tsx.
 *
 * Live-refreshes on every sync-state change (not just on screen focus),
 * same pattern already established by History/Notifications/Sync Status —
 * a queued row's sync_status can flip to synced from a background pull
 * while the agent is sitting on this exact screen.
 */
export default function ResourcesScreen() {
    const router = useRouter();
    const [expenditures, setExpenditures] = useState<LocalExpenditure[]>([]);
    const [serverRows, setServerRows] = useState<Record<string, ExpenditureApi>>({});
    const [categories, setCategories] = useState<LocalExpenseCategory[]>([]);
    const [categoryFilter, setCategoryFilter] = useState<string | null>(null);
    const [period, setPeriod] = useState<ExpenditurePeriod>('today');
    const [periodTotal, setPeriodTotal] = useState<number | null>(null);
    const [loaded, setLoaded] = useState(false);

    const refreshList = useCallback(() => {
        void Promise.all([getRecentExpenditures(), getExpenseCategories(), getCachedExpenseStatuses()]).then(
            ([rows, cats, statuses]) => {
                setExpenditures(rows);
                setCategories(cats);
                setServerRows(statuses);
                setLoaded(true);
            },
        );
    }, []);

    const refreshTotal = useCallback((forPeriod: ExpenditurePeriod) => {
        void getExpenditureTotalSince(periodStartDate(forPeriod)).then(setPeriodTotal);
    }, []);

    useFocusEffect(
        useCallback(() => {
            refreshList();
            refreshTotal(period);

            if (getSyncState().isOnline) {
                // Fresh server statuses; lands via the sync-state subscription below.
                void syncManager.refreshMyExpenseStatuses();
            }
            // Deliberately re-runs whenever `period` changes too (not just
            // on focus transitions) — useFocusEffect re-invokes its
            // callback on any dependency change while the screen stays
            // focused, so switching the Today/Week/Month chip refetches
            // the total without needing a second effect.
        }, [refreshList, refreshTotal, period]),
    );

    useEffect(
        () =>
            subscribeSyncState(() => {
                refreshList();
                refreshTotal(period);
            }),
        [refreshList, refreshTotal, period],
    );

    const categoryMap = useMemo(() => new Map(categories.map((c) => [c.uuid, c])), [categories]);

    const filtered = useMemo(
        () => filterExpendituresByCategory(expenditures, categoryFilter),
        [expenditures, categoryFilter],
    );

    const categoryChips = useMemo(
        () => [{ uuid: null as string | null, name: 'All' }, ...categories.map((c) => ({ uuid: c.uuid, name: c.name }))],
        [categories],
    );

    if (loaded && expenditures.length === 0) {
        return (
            <View style={styles.flex}>
                <EmptyState
                    title="No expenses yet"
                    subtitle="Expenses you record show up here, newest first, with their approval status."
                    actionLabel="Record an expense"
                    onAction={() => router.push('/record-expense')}
                />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <View style={styles.header}>
                <Card variant="filled" fillColor={colors.accent.expense}>
                    <View style={styles.heroTopRow}>
                        <Text style={styles.heroLabel}>{`TOTAL — ${periodLabel(period).toUpperCase()}`}</Text>
                        <View style={styles.heroGlyphBadge}>
                            <Text style={styles.heroGlyphText}>₣</Text>
                        </View>
                    </View>
                    <Text style={styles.heroValue}>{periodTotal === null ? '—' : formatFcfa(periodTotal)}</Text>
                    <Text style={styles.heroHint}>From this device — renders instantly, even offline.</Text>
                </Card>

                <Button title="+ Record an expense" variant="secondary" onPress={() => router.push('/record-expense')} />

                <View style={styles.periodRow}>
                    {EXPENDITURE_PERIODS.map((p) => {
                        const active = p === period;

                        return (
                            <Pressable
                                key={p}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => setPeriod(p)}
                                style={[styles.periodChip, active && styles.periodChipActive]}
                            >
                                <Text style={[styles.periodChipText, active && styles.periodChipTextActive]}>
                                    {periodLabel(p)}
                                </Text>
                            </Pressable>
                        );
                    })}
                </View>

                <FlatList
                    horizontal
                    data={categoryChips}
                    keyExtractor={(c) => c.uuid ?? 'all'}
                    showsHorizontalScrollIndicator={false}
                    contentContainerStyle={styles.categoryChipRow}
                    renderItem={({ item }) => {
                        const active = item.uuid === categoryFilter;

                        return (
                            <Pressable
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => setCategoryFilter(item.uuid)}
                                style={[styles.chip, active && styles.chipActive]}
                            >
                                <Text style={[styles.chipText, active && styles.chipTextActive]}>{item.name}</Text>
                            </Pressable>
                        );
                    }}
                />
            </View>

            {filtered.length === 0 ? (
                <View style={styles.emptyFiltered}>
                    <Text style={styles.emptyFilteredText}>No expenses in this category.</Text>
                </View>
            ) : (
                <FlatList
                    data={filtered}
                    keyExtractor={(item) => item.local_uuid}
                    contentContainerStyle={styles.listContent}
                    renderItem={({ item }) => {
                        const category = categoryMap.get(item.category_uuid);
                        const badge = expenseRowBadge(item, serverRows);
                        const server = item.server_uuid ? serverRows[item.server_uuid] : undefined;
                        const waitingForReceipt = server?.status === 'pending_evidence';

                        return (
                            <Pressable
                                accessibilityRole="button"
                                accessibilityLabel={`${category?.name ?? 'Expense'}, ${formatFcfa(item.amount)}, ${badge.label}`}
                                onPress={() => router.push(`/expense/${item.local_uuid}`)}
                                style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
                            >
                                <View style={styles.rowTop}>
                                    <View style={styles.categoryGlyph}>
                                        <Text style={styles.categoryGlyphText}>
                                            {glyphForCategoryIcon(category?.icon, category?.name ?? '?')}
                                        </Text>
                                    </View>
                                    <View style={styles.rowMain}>
                                        <Text style={styles.categoryName} numberOfLines={1}>
                                            {category?.name ?? server?.category_name ?? 'Unknown category'}
                                        </Text>
                                        <Text style={styles.rowMeta} numberOfLines={1}>
                                            {formatShortDate(item.spent_at)}
                                            {item.description ? ` · ${item.description}` : ''}
                                        </Text>
                                    </View>
                                    <View style={styles.rowEnd}>
                                        <Text style={styles.amount}>{formatFcfa(item.amount)}</Text>
                                        <Badge label={badge.label} tone={badge.tone} />
                                    </View>
                                </View>
                                {server && server.flags.length > 0 ? (
                                    <View style={styles.flagsRow}>
                                        <FlagChips flags={server.flags} limit={2} />
                                    </View>
                                ) : null}
                                {waitingForReceipt ? (
                                    <Text style={styles.receiptNudge}>Tap to add the receipt photo</Text>
                                ) : server?.status === 'rejected' && server.rejection_reason ? (
                                    <Text style={styles.rejectedNote} numberOfLines={1}>
                                        Rejected: {server.rejection_reason}
                                    </Text>
                                ) : null}
                            </Pressable>
                        );
                    }}
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    header: { padding: spacing.lg, paddingBottom: spacing.sm, gap: spacing.md },
    // Hero total card — mirrors Home's "Today's collection" treatment
    // (app/(tabs)/index.tsx) but with accent.expense's fill instead of
    // accent.payment's, and plain white (colors.textInverse) rather than
    // Home's '#ECFDF5' for label/hint text: that exact near-white was
    // verified only against accent.payment's emerald-800, per that
    // screen's own comment — reusing it here without re-verifying would be
    // exactly the mistake mobile-app-react-native.md §10's brief warns
    // against. Plain white is safe: the same "white text on fill" pairing
    // already verified at ~8.72:1 for accent.expense (#6B21A8) in that
    // section's contrast table.
    heroTopRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    heroLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textInverse, letterSpacing: 0.6 },
    heroGlyphBadge: {
        width: 36,
        height: 36,
        borderRadius: 18,
        backgroundColor: colors.textInverse,
        alignItems: 'center',
        justifyContent: 'center',
    },
    heroGlyphText: { fontSize: fontSize.md, fontWeight: '800', color: colors.accent.expense },
    heroValue: { fontSize: fontSize.display, fontWeight: '800', color: colors.textInverse, marginTop: spacing.sm },
    heroHint: { fontSize: fontSize.xs, color: colors.textInverse, marginTop: spacing.xs },
    periodRow: { flexDirection: 'row', gap: spacing.sm },
    periodChip: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: touchTarget.floor,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    periodChipActive: { borderColor: colors.accent.expense, backgroundColor: colors.accent.expense },
    periodChipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    periodChipTextActive: { color: colors.textInverse },
    categoryChipRow: { gap: spacing.sm },
    // 48dp floor per mobile-app-react-native.md §6 — same fix already
    // applied to History's/Customers list's identical filter-chip pattern.
    chip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: { borderColor: colors.accent.expense, backgroundColor: colors.accent.expense },
    chipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipTextActive: { color: colors.textInverse },
    listContent: { paddingHorizontal: spacing.lg, paddingBottom: spacing.xxl, gap: spacing.sm },
    row: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.lg,
        padding: spacing.md,
        backgroundColor: colors.surface,
        marginBottom: spacing.sm,
    },
    rowPressed: { opacity: 0.85 },
    rowTop: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    flagsRow: { marginTop: spacing.sm, marginLeft: 40 + spacing.md },
    receiptNudge: {
        marginTop: spacing.sm,
        marginLeft: 40 + spacing.md,
        fontSize: fontSize.sm,
        fontWeight: '700',
        color: colors.status.offlineFg,
    },
    rejectedNote: { marginTop: spacing.sm, marginLeft: 40 + spacing.md, fontSize: fontSize.sm, color: colors.danger },
    categoryGlyph: {
        width: 40,
        height: 40,
        borderRadius: radius.lg,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.surfaceMuted,
    },
    categoryGlyphText: { fontSize: fontSize.lg },
    rowMain: { flex: 1, gap: 2 },
    categoryName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    rowMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
    rowEnd: { alignItems: 'flex-end', gap: spacing.xs },
    amount: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    emptyFiltered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
    emptyFilteredText: { fontSize: fontSize.md, color: colors.textSecondary },
});
