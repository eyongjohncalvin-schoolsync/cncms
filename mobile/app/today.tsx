import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { fetchToday, type TodayOverview, type ZoneRow } from '../src/api/today';
import { extractErrorMessage } from '../src/api/client';
import { formatFcfa } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

const fcfa = (amount: string | number) => formatFcfa(Math.round(Number(amount)));
const pct = (value: number | null) => (value === null ? '—' : `${Math.round(value)}%`);

/**
 * Today (docs/plans/mobile-manager-app.md, wave 5) — read only: what came
 * in today and from whom, the month so far, the zones, and the Money
 * balance checks that prove the money adds up. Pull to refresh.
 */
export default function TodayScreen() {
    const [today, setToday] = useState<TodayOverview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async () => {
        setRefreshing(true);

        try {
            setToday(await fetchToday());
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load today. Check your connection.'));
        } finally {
            setRefreshing(false);
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    if (!today) {
        return error ? <EmptyState title="Not loaded" subtitle={error} actionLabel="Try again" onAction={() => void load()} /> : <View style={styles.flex} />;
    }

    const problems = today.balance.checks.filter((check) => check.status !== 'ok');

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load()} />}>
            <Stack.Screen options={{ title: 'Today' }} />

            {error ? <Text style={styles.error}>{error}</Text> : null}

            <Card accentColor={colors.accent.payment}>
                <Text style={styles.label}>Collected today</Text>
                <Text style={styles.big}>{fcfa(today.collections.total)}</Text>
                <Text style={styles.meta}>
                    {today.collections.count} {today.collections.count === 1 ? 'payment' : 'payments'}
                    {today.collections.by_method.length > 0 ? ' · ' + today.collections.by_method.map((row) => `${row.label} ${fcfa(row.total)}`).join(' · ') : ''}
                </Text>
            </Card>

            {today.collections.by_collector.length > 0 ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Who collected</Text>
                    {today.collections.by_collector.map((row) => (
                        <View key={row.name} style={styles.row}>
                            <Text style={styles.rowLabel}>{row.name}</Text>
                            <Text style={styles.rowValue}>
                                {fcfa(row.total)} · {row.count}
                            </Text>
                        </View>
                    ))}
                </View>
            ) : null}

            <Card accentColor={colors.accent.history}>
                <Text style={styles.label}>This month ({today.month.period})</Text>
                <Text style={styles.big}>{fcfa(today.month.collected)}</Text>
                <Text style={styles.meta}>
                    {pct(today.month.collection_rate)} of the bills collected · arrears {fcfa(today.month.arrears)}
                </Text>
                {Number(today.balance.with_collectors) > 0 ? <Text style={styles.meta}>{fcfa(today.balance.with_collectors)} still with collectors or waiting to be received.</Text> : null}
            </Card>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Money balance</Text>
                {problems.length === 0 ? <Text style={styles.ok}>Everything adds up.</Text> : null}
                {problems.map((check) => (
                    <View key={check.key} style={[styles.check, check.status === 'danger' ? styles.checkDanger : styles.checkWarning]}>
                        <Text style={styles.checkTitle}>{check.title}</Text>
                        <Text style={styles.meta}>{check.detail}</Text>
                    </View>
                ))}
                {problems.length > 0 ? <Text style={styles.meta}>{today.balance.checks.length - problems.length} other checks are fine.</Text> : null}
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Zones</Text>
                {!today.zones.judged ? <Text style={styles.meta}>Too early in the month to judge the zones.</Text> : null}
                {today.zones.dropped.length > 0 ? <ZoneList title="Well below their usual" zones={today.zones.dropped} tone="danger" /> : null}
                {today.zones.judged && today.zones.best.length > 0 ? <ZoneList title="Best" zones={today.zones.best} tone="good" /> : null}
                {today.zones.judged && today.zones.worst.length > 0 ? <ZoneList title="Weakest" zones={today.zones.worst} tone="warning" /> : null}
            </View>
        </ScrollView>
    );
}

function ZoneList({ title, zones, tone }: { title: string; zones: ZoneRow[]; tone: 'good' | 'warning' | 'danger' }) {
    return (
        <View style={styles.zoneBlock}>
            <Text style={[styles.zoneTitle, tone === 'danger' && styles.danger]}>{title}</Text>
            {zones.map((zone) => (
                <View key={zone.uuid} style={styles.row}>
                    <Text style={styles.rowLabel}>
                        {zone.name}
                        {zone.collector ? ` — ${zone.collector}` : ''}
                    </Text>
                    <Text style={styles.rowValue}>
                        {pct(zone.paid_share)} paid{zone.usual_paid_share !== null ? ` (usual ${pct(zone.usual_paid_share)})` : ''}
                    </Text>
                </View>
            ))}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    label: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary },
    big: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    ok: { fontSize: fontSize.sm, fontWeight: '700', color: colors.status.verifiedFg },
    row: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm, paddingVertical: spacing.xs, borderBottomWidth: 1, borderBottomColor: colors.border },
    rowLabel: { flex: 1, fontSize: fontSize.sm, color: colors.textPrimary },
    rowValue: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    check: { padding: spacing.sm, borderRadius: radius.md, borderLeftWidth: 4 },
    checkWarning: { backgroundColor: colors.status.pendingBg, borderLeftColor: colors.status.pendingFg },
    checkDanger: { backgroundColor: colors.status.errorBg, borderLeftColor: colors.danger },
    checkTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    zoneBlock: { gap: 2 },
    zoneTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginTop: spacing.xs },
    danger: { color: colors.danger },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
});
