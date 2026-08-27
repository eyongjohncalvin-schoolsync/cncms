import { useCallback, useEffect, useState } from 'react';
import { View, Text, ScrollView, Pressable, ActivityIndicator, StyleSheet } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { getDatabase } from '../src/db/database';
import { getZoneSnapshot, type ZoneSnapshot } from '../src/db/customers';
import { subscribeSyncState } from '../src/sync/syncStore';
import { Card } from '../src/components/ui/Card';
import { StatCard } from '../src/components/ui/StatCard';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import { formatFcfa } from '../src/utils/format';
import type { TenantRole } from '../src/types/api';

/**
 * Reports — plain-numeral summary of an agent's own collection performance
 * plus their cached zone snapshot. NOT a mobile port of the web app's
 * Daily/Weekly/Monthly Reports (resources/tsx/pages/Reports/Index.tsx,
 * App\Services\ReportService) — this app's §6 hard rule is "no charts
 * anywhere," and the web tiers are chart-heavy (LineChart/BarChart trend,
 * league tables, arrears aging buckets, a monthly P&L block). Scoped down to
 * what's genuinely useful standing in a zone with a phone: large numerals
 * for "how much have I collected," nothing more.
 *
 * DATA SOURCE — local SQLite only, deliberately, not a live API call:
 * App\Http\Controllers\ReportController is wired only into
 * routes/web/reports.php (an Inertia, session-authenticated web route) —
 * there is no routes/api/*.php equivalent (grepped both `routes/api.php`
 * and every file under `routes/api/`, confirmed empty for "report"). The
 * Sanctum-token API this app's `apiClient` (src/api/client.ts) talks to has
 * no reachable /reports endpoint today. So even though
 * App\Services\ReportService *does* correctly zone-fence an agent caller
 * (agentZoneId() resolves the caller's own Agent.zone_id and every query
 * fences on it — see fenceZones()/buildDaily() etc.), that correctness is
 * moot for mobile: there is nothing to call. Building a new backend API
 * endpoint is out of this task's/agent's scope (mobile-only file ownership
 * for this build wave) — flagged here, not silently worked around.
 * Consequently this screen's "period totals" are computed directly against
 * the local `payments` table (this device's own outbox+history — see
 * mobile-app-react-native.md §2), the exact same source and exclude-
 * rejected convention Home's "Today's collection" hero already uses
 * (src/db/payments.ts's getTodayCollectionTotal) — the "Today" figure here
 * is deliberately kept numerically identical to Home's, computed the same
 * way, so the two screens never disagree.
 *
 * "Your zone" below reuses src/db/customers.ts's getZoneSnapshot() verbatim
 * — the exact same local-cache aggregate query Home's "Zone arrears
 * outstanding" card already uses. This is genuinely safe to show an agent:
 * the local `customers` cache is populated only from this device's own
 * pull() response, which is itself already scoped to the authenticated
 * agent (see mobile-app-react-native.md §2's schema note) — there is no
 * path by which a wider, cross-zone figure could end up in this table. No
 * branch/company-wide figures are shown or attempted, per the brief's
 * "default to only what's provable safe" instruction.
 *
 * ROLE GATE — mirrors App\Policies\ReportPolicy::view() exactly (super,
 * admin, manager, agent; `worker` excluded) — same role list the web
 * sidebar's REPORTS_ROLES constant uses (resources/tsx/layouts/
 * AppLayout.tsx). The policy's additional `is_investor` OR-branch is
 * deliberately NOT replicated here: `is_investor` is never sent to this
 * app (grepped src/types/api.ts's TenantRole/auth response shapes — no
 * `is_investor` field anywhere), and rbac-permissions.md §7 describes the
 * Investor tier as a dedicated web-only `InvestorLayout.tsx` experience,
 * not a mobile concept — so there is nothing to check for it here.
 *
 * NO EXPORT — ReportPolicy::export() is super/admin/manager only, and even
 * for those roles there is no PDF export endpoint reachable from mobile
 * (export() lives on the same web-only routes/web/reports.php as index()).
 * No export button is built for any role.
 *
 * NO CHARTS — plain StatCard/Card numeral tiles only, per §6. The one
 * "hero" figure per screen (Card variant="filled") is the selected period's
 * collection total; everything else is an ordinary outlined Card/StatCard.
 * Accent color: reuses `colors.accent.history` (amber-800) rather than
 * introducing a new token — the closest existing hue family to the web
 * sidebar's 'orange' Reports nav accent (AppLayout.tsx's reportsNavItem),
 * and its white-on-fill pairing is already verified AAA (~7.63:1, see
 * colors.ts's accent.history comment) for exactly this treatment (filled
 * card / active filter chip), so no new contrast math was needed.
 */

const VIEW_ALLOWED_ROLES = new Set<TenantRole>(['super', 'admin', 'manager', 'agent']);

type Period = 'today' | 'week' | 'month';

const PERIODS: Array<{ key: Period; chipLabel: string; heroLabel: string }> = [
    { key: 'today', chipLabel: 'Today', heroLabel: "TODAY'S COLLECTION" },
    { key: 'week', chipLabel: 'This Week', heroLabel: "THIS WEEK'S COLLECTION" },
    { key: 'month', chipLabel: 'This Month', heroLabel: "THIS MONTH'S COLLECTION" },
];

interface PeriodSummary {
    /** Verified + pending amounts (rejected excluded) — matches
     * src/db/payments.ts's getTodayCollectionTotal() convention exactly,
     * so this screen's "Today" figure never disagrees with Home's. */
    total: number;
    verified: number;
    pending: number;
    rejected: number;
    count: number;
}

function startOfTodayLocal(): Date {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

/** Monday start, matching App\Services\ReportService::weekly()'s
 * Carbon::MONDAY convention — kept consistent even though this is a purely
 * local, unrelated computation, so "This Week" means the same thing an
 * office user's web Weekly tier would mean. */
function startOfThisWeekLocal(): Date {
    const start = startOfTodayLocal();
    const day = start.getDay(); // 0 = Sunday .. 6 = Saturday
    const diffToMonday = day === 0 ? 6 : day - 1;
    start.setDate(start.getDate() - diffToMonday);

    return start;
}

function startOfThisMonthLocal(): Date {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), 1);
}

function sinceIsoFor(period: Period): string {
    const start =
        period === 'today' ? startOfTodayLocal() : period === 'week' ? startOfThisWeekLocal() : startOfThisMonthLocal();

    return start.toISOString();
}

async function getPeriodSummary(period: Period): Promise<PeriodSummary> {
    const db = await getDatabase();
    const since = sinceIsoFor(period);

    const row = await db.getFirstAsync<{
        verified: number | null;
        pending: number | null;
        rejected: number | null;
        count: number;
    }>(
        `SELECT
            COALESCE(SUM(CASE WHEN verification_status = 'verified' THEN amount ELSE 0 END), 0) as verified,
            COALESCE(SUM(CASE WHEN verification_status = 'pending' THEN amount ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN verification_status = 'rejected' THEN amount ELSE 0 END), 0) as rejected,
            COUNT(*) as count
         FROM payments
         WHERE created_at >= ?`,
        [since],
    );

    const verified = row?.verified ?? 0;
    const pending = row?.pending ?? 0;
    const rejected = row?.rejected ?? 0;

    return {
        total: verified + pending,
        verified,
        pending,
        rejected,
        count: row?.count ?? 0,
    };
}

export default function ReportsScreen() {
    const { role, status } = useAuth();
    const [period, setPeriod] = useState<Period>('today');
    const [summary, setSummary] = useState<PeriodSummary | null>(null);
    const [zoneSnapshot, setZoneSnapshot] = useState<ZoneSnapshot | null>(null);

    const authorized = role !== null && VIEW_ALLOWED_ROLES.has(role);

    const refresh = useCallback(() => {
        if (!authorized) {
            return;
        }

        void getPeriodSummary(period).then(setSummary);
        void getZoneSnapshot().then(setZoneSnapshot);
    }, [period, authorized]);

    useFocusEffect(refresh);

    // Live-refreshes on every sync-state change, same pattern History/
    // Notifications already use — a payment recorded moments ago (still
    // 'pending') can flip to 'verified' via a background pull while the
    // agent is sitting on this exact screen.
    useEffect(() => subscribeSyncState(refresh), [refresh]);

    if (status === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Reports' }} />
                <ActivityIndicator size="large" color={colors.accent.history} />
            </View>
        );
    }

    if (!authorized) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Reports' }} />
                <View style={styles.content}>
                    <Card accentColor={colors.status.offlineDot}>
                        <Text style={styles.notAuthorizedTitle}>Reports aren't available for your role</Text>
                        <Text style={styles.notAuthorizedBody}>
                            Reports are available to field agents, managers, admins, and super users. Contact your office
                            if you believe this is a mistake.
                        </Text>
                    </Card>
                </View>
            </View>
        );
    }

    const activePeriod = PERIODS.find((p) => p.key === period) ?? PERIODS[0];

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Reports' }} />

            <View style={styles.periodRow}>
                {PERIODS.map((p) => {
                    const active = p.key === period;

                    return (
                        <Pressable
                            key={p.key}
                            accessibilityRole="button"
                            accessibilityState={{ selected: active }}
                            onPress={() => setPeriod(p.key)}
                            style={[styles.chip, active && styles.chipActive]}
                        >
                            <Text style={[styles.chipText, active && styles.chipTextActive]}>{p.chipLabel}</Text>
                        </Pressable>
                    );
                })}
            </View>

            {/* The one hero card on this screen — the selected period's
                collection total. See file header comment for why
                accent.history (not accent.payment, already Home's hero
                color) was chosen and why its white-on-fill pairing needed
                no new contrast verification. */}
            <Card variant="filled" fillColor={colors.accent.history}>
                <Text style={styles.heroLabel}>{activePeriod.heroLabel}</Text>
                <Text style={styles.heroValue}>{summary === null ? '—' : formatFcfa(summary.total)}</Text>
                <Text style={styles.heroHint}>
                    {summary === null
                        ? 'Loading…'
                        : `${summary.count} payment${summary.count === 1 ? '' : 's'} recorded on this device`}
                </Text>
            </Card>

            <View style={styles.statRow}>
                <StatCard label="Verified" value={summary === null ? '—' : formatFcfa(summary.verified)} tone="payment" />
                <StatCard label="Pending" value={summary === null ? '—' : formatFcfa(summary.pending)} tone="slate" />
            </View>

            {summary !== null && summary.rejected > 0 ? (
                <Card accentColor={colors.danger}>
                    <Text style={styles.totalLabel}>Rejected</Text>
                    <Text style={[styles.totalValue, styles.totalValueDanger]}>{formatFcfa(summary.rejected)}</Text>
                    <Text style={styles.totalHint}>Not counted in the total above — see My Recorded Payments for reasons.</Text>
                </Card>
            ) : null}

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Your zone (current snapshot)</Text>
                <Card accentColor={zoneSnapshot && zoneSnapshot.arrearsTotal > 0 ? colors.danger : undefined}>
                    <Text style={styles.totalLabel}>Arrears outstanding</Text>
                    <Text
                        style={[
                            styles.totalValue,
                            zoneSnapshot && zoneSnapshot.arrearsTotal > 0 ? styles.totalValueDanger : styles.totalValueNeutral,
                        ]}
                    >
                        {zoneSnapshot === null ? '—' : formatFcfa(zoneSnapshot.arrearsTotal)}
                    </Text>
                    <Text style={styles.totalHint}>
                        {zoneSnapshot === null
                            ? 'Loading…'
                            : zoneSnapshot.owesMoneyCount === 0
                              ? 'Every cached customer is paid up'
                              : `${zoneSnapshot.owesMoneyCount} customer${zoneSnapshot.owesMoneyCount === 1 ? '' : 's'} owe money`}
                    </Text>
                </Card>
                <StatCard
                    label="Disconnected"
                    value={zoneSnapshot === null ? '—' : String(zoneSnapshot.disconnectedCount)}
                    hint="Still on your route"
                    tone="amber"
                />
            </View>

            <Text style={styles.footnote}>
                Figures come from payments recorded on this device and your cached zone data — they refresh automatically
                as you sync.
            </Text>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    periodRow: { flexDirection: 'row', gap: spacing.sm },
    chip: {
        flex: 1,
        paddingHorizontal: spacing.md,
        // 48dp floor per mobile-app-react-native.md §6 — tapped on nearly
        // every visit to this screen, same "resize, not hitSlop" call the
        // History/Customers filter-chip audits made for identically-shaped
        // controls.
        minHeight: touchTarget.floor,
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: {
        borderColor: colors.accent.history,
        backgroundColor: colors.accent.history,
    },
    chipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipTextActive: { color: colors.textInverse },
    heroLabel: {
        fontSize: fontSize.sm,
        fontWeight: '700',
        // Plain colors.textInverse (solid white), NOT a custom near-white
        // tint — colors.ts's accent.history entry documents ~7.63:1 for
        // white-on-fill specifically, so this pairing is already verified;
        // inventing a new tinted white here (as Home's hero card does,
        // verified only against accent.payment) would need its own,
        // unnecessary re-verification.
        color: colors.textInverse,
        letterSpacing: 0.6,
    },
    heroValue: { fontSize: fontSize.display, fontWeight: '800', color: colors.textInverse, marginTop: spacing.sm },
    heroHint: { fontSize: fontSize.xs, color: colors.textInverse, marginTop: spacing.xs },
    statRow: { flexDirection: 'row', gap: spacing.md },
    totalLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    totalValue: { fontSize: fontSize.xxl, fontWeight: '800', marginTop: spacing.xs },
    totalValueDanger: { color: colors.danger },
    totalValueNeutral: { color: colors.textPrimary },
    totalHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    footnote: { fontSize: fontSize.xs, color: colors.textSecondary, textAlign: 'center' },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
