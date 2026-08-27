import { useCallback, useEffect, useMemo, useState } from 'react';
import { View, Text, FlatList, Image, Pressable, StyleSheet } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { EmptyState } from '../src/components/ui/EmptyState';
import { getRecentExpenditures, getExpenditureTotalSince } from '../src/db/expenditures';
import { getExpenseCategories } from '../src/db/categories';
import { subscribeSyncState } from '../src/sync/syncStore';
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
 * Live-refreshes on every sync-state change (not just on screen focus),
 * same pattern already established by History/Notifications/Sync Status —
 * a queued row's sync_status can flip to synced from a background pull
 * while the agent is sitting on this exact screen.
 */
export default function ResourcesScreen() {
    const [expenditures, setExpenditures] = useState<LocalExpenditure[]>([]);
    const [categories, setCategories] = useState<LocalExpenseCategory[]>([]);
    const [categoryFilter, setCategoryFilter] = useState<string | null>(null);
    const [period, setPeriod] = useState<ExpenditurePeriod>('today');
    const [periodTotal, setPeriodTotal] = useState<number | null>(null);
    const [expandedLocalUuid, setExpandedLocalUuid] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);

    const refreshList = useCallback(() => {
        void Promise.all([getRecentExpenditures(), getExpenseCategories()]).then(([rows, cats]) => {
            setExpenditures(rows);
            setCategories(cats);
            setLoaded(true);
        });
    }, []);

    const refreshTotal = useCallback((forPeriod: ExpenditurePeriod) => {
        void getExpenditureTotalSince(periodStartDate(forPeriod)).then(setPeriodTotal);
    }, []);

    useFocusEffect(
        useCallback(() => {
            refreshList();
            refreshTotal(period);
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
                    title="Resources"
                    subtitle="Expenses you record will show up here, newest first, with their sync status."
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
                        const isExpanded = expandedLocalUuid === item.local_uuid;
                        const badge = SYNC_BADGE[item.sync_status];

                        return (
                            <Pressable
                                onPress={() => setExpandedLocalUuid(isExpanded ? null : item.local_uuid)}
                                style={styles.row}
                            >
                                <View style={styles.rowTop}>
                                    <View style={styles.categoryGlyph}>
                                        <Text style={styles.categoryGlyphText}>
                                            {glyphForCategoryIcon(category?.icon, category?.name ?? '?')}
                                        </Text>
                                    </View>
                                    <View style={styles.rowMain}>
                                        <Text style={styles.categoryName} numberOfLines={1}>
                                            {category?.name ?? 'Unknown category'}
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
                                {isExpanded ? (
                                    <View style={styles.detailBox}>
                                        {item.description ? (
                                            <>
                                                <Text style={styles.detailLabel}>Description</Text>
                                                <Text style={styles.detailText}>{item.description}</Text>
                                            </>
                                        ) : null}
                                        {item.receipt_local_uri ? (
                                            <Image source={{ uri: item.receipt_local_uri }} style={styles.receiptPreview} />
                                        ) : (
                                            <Text style={styles.detailHint}>No receipt photo attached.</Text>
                                        )}
                                    </View>
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
    rowTop: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
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
    detailBox: {
        marginTop: spacing.sm,
        paddingTop: spacing.sm,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        gap: spacing.xs,
    },
    detailLabel: { fontSize: fontSize.xs, fontWeight: '700', color: colors.textSecondary },
    detailText: { fontSize: fontSize.sm, color: colors.textPrimary },
    detailHint: { fontSize: fontSize.sm, color: colors.textSecondary },
    receiptPreview: {
        width: '100%',
        height: 180,
        borderRadius: radius.md,
        backgroundColor: colors.surfaceMuted,
        marginTop: spacing.xs,
    },
    emptyFiltered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
    emptyFilteredText: { fontSize: fontSize.md, color: colors.textSecondary },
});
