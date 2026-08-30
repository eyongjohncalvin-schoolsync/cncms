import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { currentPeriod, fetchManuscripts } from '../src/api/manuscripts';
import { isNetworkError, extractErrorMessage } from '../src/api/client';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { Card } from '../src/components/ui/Card';
import { StatCard } from '../src/components/ui/StatCard';
import { EmptyState } from '../src/components/ui/EmptyState';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import { formatFcfa } from '../src/utils/format';
import type { ManuscriptListItemApi, ManuscriptSummaryApi, TenantRole } from '../src/types/api';

/**
 * Manuscript — a modest, read-only view of this agent's own zone's current
 * billing-period figures. Product owner's own framing: "though I don't
 * think is that necessary, but I think is good to have the feature" — so
 * this is deliberately NOT a mobile port of the web Manuscripts register
 * (resources/tsx/pages/Manuscripts/Index.tsx), which is a full paginated,
 * filterable, exportable register meant for office staff reviewing every
 * zone. This screen shows both a zone-level summary (the single most useful
 * glance for a field agent — "how much is billed/owed/collected this
 * period") AND the underlying per-customer figures (bill/arrears/credit/
 * total_bill), since a real zone is only "low hundreds" of customers
 * (mobile-app-react-native.md §2) — small enough that the full list is
 * still a genuinely quick scroll, not the kind of thing that needs an
 * office-grade paginated table.
 *
 * PERIOD SAFETY — see src/api/manuscripts.ts's fetchManuscripts() doc
 * comment for the full incident writeup. In short: this screen NEVER
 * trusts "latest manuscript of any period" (the relationship a real 2026-08
 * incident found could silently pick up bogus future-dated rows as
 * "current" for every customer). Every request carries an explicit, real
 * calendar period as 'YYYY-MM', which the server independently re-validates
 * (App\Services\ManuscriptService::scopedFilters()). The month stepper
 * (2026-08-30 addendum) lets the viewer page between months, but only ever
 * within a hard [EARLIEST_PERIOD .. latestPeriod()] window computed from
 * real calendar arithmetic (shiftPeriod()) — latestPeriod() is one month
 * ahead of today, since the cycle generates next month's manuscript in
 * advance (the September register exists and is reviewed during August),
 * but never further, and never before v2's first real run. It is still
 * "an explicit calendar period", never "whatever sorts highest".
 *
 * ZONE SAFETY — this screen sends no zone_uuid at all. The server
 * force-scopes an `agent` caller to their own zone regardless of what's
 * requested (a fix landed alongside this screen — see
 * Api\ManuscriptController::index()'s doc comment) — there is nothing for
 * this client to usefully choose. Office roles (manager/admin/super) are
 * unscoped, matching the web register's own default (see the truncation
 * footnote below for how that's handled at this screen's page-size cap).
 *
 * READ-ONLY, ON PURPOSE — no bill-print, no PDF export, no "run manuscript
 * calculation" trigger. Matches App\Policies\ManuscriptPolicy exactly:
 * export() is super/admin/manager only (mirrors the web's EXPORT_ROLES) and
 * calculate() is super/admin only (mirrors CALCULATE_ROLES) — neither is
 * replicated here regardless of the viewing role; this screen only ever
 * calls the plain viewAny()-gated index endpoint. Rows are plain
 * non-interactive Cards — no drill-down into Customer Detail, no bill-send
 * action — this is a glance, not a workflow.
 *
 * ONLINE-ONLY — like app/disconnections.tsx, these figures are computed
 * live server-side on every call (no local SQLite cache of manuscript rows
 * exists — the offline `customers` cache only ever carries each customer's
 * own *latest* arrears/credit for other screens, never a full period
 * register), so there is no offline fallback. Retries automatically once
 * connectivity returns, same subscribeSyncState pattern as Disconnections/
 * Reconnect/Disconnect.
 *
 * NO CHARTS — plain numeral Card/StatCard tiles only, per §6. One hero card
 * (Card variant="filled") for the period's total billed figure; everything
 * else is an ordinary outlined Card/StatCard. Accent: `colors.accent.history`
 * — reused, not new (mobile-app-react-native.md §10's brief for new-screen
 * builders: don't introduce a new fill color without re-verifying contrast).
 * The web sidebar's actual Manuscripts nav accent is literally 'amber'
 * (resources/tsx/layouts/AppLayout.tsx) — `accent.history` already is that
 * exact hue family in this app's palette (reports.tsx's own doc comment
 * notes the same token as "closest existing hue" for a different, merely
 * adjacent web accent — here it's an exact match), and its white-on-fill
 * pairing is already independently verified AAA (~7.63:1, colors.ts).
 *
 * Registration into a tab bar / More entry is a separate step, per this
 * build's cross-agent convention (mobile-app-react-native.md §11/§12) —
 * this route is simply `/manuscript`.
 */

const VIEW_ALLOWED_ROLES = new Set<TenantRole>(['super', 'admin', 'manager', 'agent']);

// The first period v2's monthly cycle actually produced (see
// project-manuscript-monthly-cycle: the imported "2026-08" baseline is v1's
// 2026-07-22 run). There is nothing to show before this, so the month
// stepper stops here rather than letting an agent page back through empty
// months forever.
const EARLIEST_PERIOD = '2026-08';

type Phase = 'loading' | 'offline' | 'error' | 'ready';

function periodLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);
    const date = new Date(year, (month || 1) - 1, 1);

    if (Number.isNaN(date.getTime())) {
        return period;
    }

    return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

/** Shift a 'YYYY-MM' period by whole months (delta may be negative). */
function shiftPeriod(period: string, delta: number): string {
    const [year, month] = period.split('-').map(Number);
    const date = new Date(year, (month || 1) - 1 + delta, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * The furthest-ahead period the stepper allows — one month past the current
 * calendar month. The billing cycle runs a month early: the run executed
 * near the end of month M produces month M+1's manuscript (the bills that
 * go out before M+1 begins — see project-manuscript-monthly-cycle), so
 * during August the September manuscript already exists and must be
 * reachable. Nothing is ever generated further ahead than that.
 */
function latestPeriod(): string {
    return shiftPeriod(currentPeriod(), 1);
}

export default function ManuscriptScreen() {
    const { role, status: authStatus } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [period, setPeriod] = useState<string>(currentPeriod());
    const [summary, setSummary] = useState<ManuscriptSummaryApi | null>(null);
    const [rows, setRows] = useState<ManuscriptListItemApi[]>([]);
    const [totalCount, setTotalCount] = useState(0);
    // True only while re-fetching a different month with data already on
    // screen — keeps the current list visible under a spinner in the month
    // stepper instead of blanking the whole screen to the 'loading' state.
    const [switching, setSwitching] = useState(false);
    const phaseRef = useRef<Phase>('loading');
    // The period the next load() should request. A ref (not just state) so
    // the focus-effect's own load() always sees the latest value without
    // being in its dependency list.
    const periodRef = useRef<string>(currentPeriod());

    const authorized = role !== null && VIEW_ALLOWED_ROLES.has(role);

    const load = useCallback(
        (options?: { silent?: boolean }) => {
            if (!authorized) {
                return;
            }

            if (!getSyncState().isOnline) {
                phaseRef.current = 'offline';
                setPhase('offline');
                setSwitching(false);
                return;
            }

            if (options?.silent) {
                setSwitching(true);
            } else {
                phaseRef.current = 'loading';
                setPhase('loading');
            }
            setErrorMessage(null);

            const requestedPeriod = periodRef.current;

            fetchManuscripts(requestedPeriod)
                .then((response) => {
                    // Ignore a response that arrived after the agent stepped
                    // to yet another month.
                    if (periodRef.current !== requestedPeriod) {
                        return;
                    }

                    const sorted = [...response.data].sort(
                        (a, b) => Number(b.total_arrears) - Number(a.total_arrears),
                    );

                    setRows(sorted);
                    setSummary(response.summary);
                    setTotalCount(response.meta?.total ?? response.data.length);
                    setPeriod(requestedPeriod);
                    phaseRef.current = 'ready';
                    setPhase('ready');
                    setSwitching(false);
                })
                .catch((error) => {
                    if (periodRef.current !== requestedPeriod) {
                        return;
                    }

                    setSwitching(false);

                    if (isNetworkError(error)) {
                        phaseRef.current = 'offline';
                        setPhase('offline');
                    } else {
                        setErrorMessage(extractErrorMessage(error, "Couldn't load this period's manuscript figures."));
                        phaseRef.current = 'error';
                        setPhase('error');
                    }
                });
        },
        [authorized],
    );

    // Step the stepper. Capped at EARLIEST_PERIOD .. latestPeriod() (one
    // month ahead — the cycle generates next month's manuscript in advance).
    const changeMonth = useCallback(
        (delta: number) => {
            const next = shiftPeriod(periodRef.current, delta);

            if (next < EARLIEST_PERIOD || next > latestPeriod()) {
                return;
            }

            periodRef.current = next;
            setPeriod(next);
            load({ silent: true });
        },
        [load],
    );

    // Same "retry automatically once connectivity returns" behavior as
    // Disconnections / Reconnect & Pay / Disconnect. On (re)focus the
    // stepper always snaps back to the real current calendar month — an
    // agent who left the app open across a month boundary should land on
    // this month, not a stale one, and shouldn't inherit a month they'd
    // paged to on a previous visit.
    useFocusEffect(
        useCallback(() => {
            periodRef.current = currentPeriod();
            setPeriod(currentPeriod());
            load();

            return subscribeSyncState(() => {
                if (phaseRef.current === 'offline' && getSyncState().isOnline) {
                    load();
                }
            });
        }, [load]),
    );

    const canGoPrev = shiftPeriod(period, -1) >= EARLIEST_PERIOD;
    const canGoNext = shiftPeriod(period, 1) <= latestPeriod();

    function renderItem({ item }: { item: ManuscriptListItemApi }) {
        const arrears = Number(item.total_arrears);
        const credit = Number(item.credit);
        const flagged = item.status !== 'active';

        return (
            <Card style={styles.row}>
                <View style={styles.rowTop}>
                    <Text style={styles.name} numberOfLines={1}>
                        {item.customer_name}
                        {flagged ? <Text style={styles.statusTag}> · {item.status}</Text> : null}
                    </Text>
                    <Text style={styles.totalBill}>{formatFcfa(Number(item.total_bill))}</Text>
                </View>
                <View style={styles.rowDetails}>
                    <Text style={styles.detailItem}>Bill {formatFcfa(Number(item.bill))}</Text>
                    <Text style={[styles.detailItem, arrears > 0 && styles.detailArrears]}>
                        Arrears {formatFcfa(arrears)}
                    </Text>
                    {credit > 0 ? <Text style={styles.detailItem}>Credit {formatFcfa(credit)}</Text> : null}
                </View>
            </Card>
        );
    }

    function renderMonthStepper() {
        return (
            <View style={styles.stepperRow}>
                <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="Previous month"
                    accessibilityState={{ disabled: !canGoPrev }}
                    disabled={!canGoPrev || switching}
                    onPress={() => changeMonth(-1)}
                    style={[styles.stepperButton, (!canGoPrev || switching) && styles.stepperButtonDisabled]}
                >
                    <Text style={styles.stepperArrow}>‹</Text>
                </Pressable>

                <View style={styles.stepperLabelWrap}>
                    <Text style={styles.stepperLabel}>{periodLabel(period)}</Text>
                    {switching ? <ActivityIndicator size="small" color={colors.accent.history} /> : null}
                </View>

                <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="Next month"
                    accessibilityState={{ disabled: !canGoNext }}
                    disabled={!canGoNext || switching}
                    onPress={() => changeMonth(1)}
                    style={[styles.stepperButton, (!canGoNext || switching) && styles.stepperButtonDisabled]}
                >
                    <Text style={styles.stepperArrow}>›</Text>
                </Pressable>
            </View>
        );
    }

    function renderHeader() {
        if (summary === null) {
            return <View style={styles.headerBlock}>{renderMonthStepper()}</View>;
        }

        return (
            <View style={styles.headerBlock}>
                {renderMonthStepper()}

                {/* The one hero card on this screen — see file header
                    comment for why accent.history needed no new contrast
                    verification. */}
                <Card variant="filled" fillColor={colors.accent.history}>
                    <Text style={styles.heroLabel}>{periodLabel(period).toUpperCase()} — TOTAL BILLED</Text>
                    <Text style={styles.heroValue}>{formatFcfa(Number(summary.total_bill))}</Text>
                    <Text style={styles.heroHint}>
                        {summary.total_customers} customer{summary.total_customers === 1 ? '' : 's'} with a manuscript
                        this period
                    </Text>
                </Card>

                <View style={styles.statRow}>
                    <StatCard
                        label="Arrears outstanding"
                        value={formatFcfa(Number(summary.total_arrears))}
                        tone={Number(summary.total_arrears) > 0 ? 'red' : 'slate'}
                    />
                    <StatCard
                        label="Collected so far"
                        value={formatFcfa(Number(summary.total_collected))}
                        hint={`${summary.collection_rate}% of this period's billing`}
                        tone="payment"
                    />
                </View>

                {Number(summary.total_credit) > 0 ? (
                    <Card accentColor={colors.accent.payment}>
                        <Text style={styles.creditLabel}>Credit balance</Text>
                        <Text style={styles.creditValue}>{formatFcfa(Number(summary.total_credit))}</Text>
                        <Text style={styles.creditHint}>Carried forward — applied against future bills.</Text>
                    </Card>
                ) : null}

                <Text style={styles.footnote}>
                    Bill/arrears/credit/collected figures above cover active customers only, matching the office
                    Manuscripts register — the customer count includes every status.
                    {rows.length > 0 && rows.length < totalCount
                        ? ` Showing ${rows.length} of ${totalCount} customers below.`
                        : ''}
                </Text>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Customers this period</Text>
                    <Text style={styles.sectionSubtitle}>Sorted by arrears owed, highest first.</Text>
                </View>
            </View>
        );
    }

    if (authStatus === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Manuscript' }} />
                <ActivityIndicator size="large" color={colors.accent.history} />
            </View>
        );
    }

    if (!authorized) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Manuscript' }} />
                <View style={styles.content}>
                    <Card accentColor={colors.status.offlineDot}>
                        <Text style={styles.notAuthorizedTitle}>Manuscript isn't available for your role</Text>
                        <Text style={styles.notAuthorizedBody}>
                            Manuscript figures are available to field agents, managers, admins, and super users. Contact
                            your office if you believe this is a mistake.
                        </Text>
                    </Card>
                </View>
            </View>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Manuscript' }} />
                <ActivityIndicator size="large" color={colors.accent.history} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Manuscript' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Manuscript figures are computed live from the server and aren't cached offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={() => load()}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Manuscript' }} />
                <EmptyState
                    title="Couldn't load manuscript figures"
                    subtitle={errorMessage ?? undefined}
                    actionLabel="Try again"
                    onAction={() => load()}
                />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <Stack.Screen options={{ title: 'Manuscript' }} />
            <FlatList
                data={rows}
                keyExtractor={(item) => item.customer_uuid}
                renderItem={renderItem}
                ListHeaderComponent={renderHeader}
                ListEmptyComponent={
                    <EmptyState
                        title="No manuscripts for this month"
                        subtitle="Nothing was calculated for this period. Use the arrows above to check another month."
                    />
                }
                contentContainerStyle={styles.listContent}
            />
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg },
    listContent: { padding: spacing.lg, paddingTop: spacing.lg, gap: spacing.sm, flexGrow: 1 },
    headerBlock: { gap: spacing.md, marginBottom: spacing.md },
    stepperRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    stepperButton: {
        width: touchTarget.floor,
        height: touchTarget.floor,
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    stepperButtonDisabled: { opacity: 0.35 },
    stepperArrow: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary, lineHeight: fontSize.xxl },
    stepperLabelWrap: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing.sm },
    stepperLabel: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    heroLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textInverse, letterSpacing: 0.6 },
    heroValue: { fontSize: fontSize.display, fontWeight: '800', color: colors.textInverse, marginTop: spacing.sm },
    heroHint: { fontSize: fontSize.xs, color: colors.textInverse, marginTop: spacing.xs },
    statRow: { flexDirection: 'row', gap: spacing.md },
    creditLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    creditValue: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.accent.payment, marginTop: spacing.xs },
    creditHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
    footnote: { fontSize: fontSize.xs, color: colors.textSecondary },
    section: { gap: spacing.xs },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    sectionSubtitle: { fontSize: fontSize.xs, color: colors.textSecondary },
    row: { gap: spacing.xs },
    rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    name: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    statusTag: { fontSize: fontSize.xs, fontWeight: '600', color: colors.textSecondary },
    totalBill: { fontSize: fontSize.md, fontWeight: '800', color: colors.textPrimary },
    rowDetails: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
    detailItem: { fontSize: fontSize.xs, color: colors.textSecondary },
    detailArrears: { color: colors.danger, fontWeight: '700' },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
