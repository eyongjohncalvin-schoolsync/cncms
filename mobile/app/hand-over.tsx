import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TextInput as RNTextInput, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Button } from '../src/components/ui/Button';
import { Badge } from '../src/components/ui/Badge';
import { EmptyState } from '../src/components/ui/EmptyState';
import { TextInput as UiTextInput } from '../src/components/ui/TextInput';
import { extractErrorMessage } from '../src/api/client';
import { fetchMyRemittances, fetchRemittancePreview, submitRemittance, type RemittancePreview, type RemittanceSummary } from '../src/api/remittances';
import { syncManager } from '../src/sync/SyncManager';
import { formatFcfa } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

/**
 * Hand over cash — the daily income form (docs/plans/cash-controls.md,
 * wave 4). The server lists what this collector recorded and hasn't handed
 * over (so sync first — the screen does it for you); the collector counts
 * the cash note by note and submits; the cashier counts it again on the web
 * and receives it. Online only.
 */
export default function HandOverScreen() {
    const [preview, setPreview] = useState<RemittancePreview | null>(null);
    const [history, setHistory] = useState<RemittanceSummary[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [counts, setCounts] = useState<Record<string, string>>({});
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            // Push anything still queued first, so it is included.
            await syncManager.syncNow('hand-over');
            const [nextPreview, nextHistory] = await Promise.all([fetchRemittancePreview(), fetchMyRemittances()]);
            setPreview(nextPreview);
            setHistory(nextHistory);
        } catch (loadError) {
            setError(extractErrorMessage(loadError, 'Could not load your collections.'));
        } finally {
            setLoading(false);
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    const counted = Object.entries(counts).reduce((sum, [value, count]) => sum + Number(value) * (Number(count) || 0), 0);

    async function handleSubmit() {
        if (!preview) {
            return;
        }

        const expected = Number(preview.totals.cash);
        const go = async () => {
            setSubmitting(true);

            try {
                const denominations = Object.fromEntries(
                    Object.entries(counts)
                        .filter(([, count]) => Number(count) > 0)
                        .map(([value, count]) => [value, Number(count)]),
                );
                const result = await submitRemittance({
                    declared_cash: counted,
                    denominations: Object.keys(denominations).length > 0 ? denominations : null,
                    note: note.trim() || null,
                });
                Alert.alert('Handed over', `${result.number}: give ${formatFcfa(counted)} to the cashier. They will count it and confirm.`);
                setCounts({});
                setNote('');
                await load();
            } catch (submitError) {
                Alert.alert('Could not hand over', extractErrorMessage(submitError));
            } finally {
                setSubmitting(false);
            }
        };

        if (counted !== expected) {
            Alert.alert(
                'Amounts differ',
                `You counted ${formatFcfa(counted)} but recorded ${formatFcfa(expected)} in cash. Hand over anyway? The difference is recorded against you.`,
                [
                    { text: 'Recount', style: 'cancel' },
                    { text: 'Hand over', style: 'destructive', onPress: () => void go() },
                ],
            );

            return;
        }

        await go();
    }

    if (loading && !preview) {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" color={colors.accent.payment} />
            </View>
        );
    }

    if (error) {
        return (
            <View style={styles.flex}>
                <EmptyState title="Can't load your collections" subtitle={`${error} You need a connection to hand over.`} actionLabel="Try again" onAction={() => void load()} />
            </View>
        );
    }

    const mobile = preview ? Number(preview.totals.mtn_momo) + Number(preview.totals.orange_money) : 0;

    return (
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            {preview && preview.count > 0 ? (
                <>
                    <Card accentColor={colors.accent.payment}>
                        <Text style={styles.label}>Cash to hand over</Text>
                        <Text style={styles.big}>{formatFcfa(Number(preview.totals.cash))}</Text>
                        <Text style={styles.meta}>
                            {preview.count} collection(s){preview.oldest_at ? ` since ${new Date(preview.oldest_at).toLocaleString()}` : ''}
                        </Text>
                        {mobile > 0 ? <Text style={styles.meta}>Mobile money {formatFcfa(mobile)} — already in the company wallet.</Text> : null}
                    </Card>

                    <Card>
                        <Text style={styles.label}>Count your cash</Text>
                        <View style={styles.grid}>
                            {preview.denominations.map((value) => (
                                <View key={value} style={styles.cell}>
                                    <Text style={styles.cellLabel}>{value.toLocaleString()} ×</Text>
                                    <RNTextInput
                                        value={counts[String(value)] ?? ''}
                                        onChangeText={(text) => setCounts((current) => ({ ...current, [String(value)]: text.replace(/\D/g, '') }))}
                                        keyboardType="number-pad"
                                        placeholder="0"
                                        style={styles.cellInput}
                                    />
                                </View>
                            ))}
                        </View>
                        <Text style={[styles.counted, counted !== Number(preview.totals.cash) && styles.countedOff]}>Counted: {formatFcfa(counted)}</Text>
                    </Card>

                    <UiTextInput label="Note (optional)" value={note} onChangeText={setNote} />
                    <Button title={submitting ? 'Handing over…' : `Hand over ${formatFcfa(counted)}`} size="large" loading={submitting} onPress={handleSubmit} />
                </>
            ) : (
                <EmptyState title="Nothing to hand over" subtitle="Everything you recorded has already been handed over." />
            )}

            <Text style={styles.section}>My recent handovers</Text>
            {history.length === 0 ? <Text style={styles.meta}>None yet.</Text> : null}
            {history.map((row) => (
                <Card key={row.uuid}>
                    <View style={styles.row}>
                        <Text style={styles.rowTitle}>{row.number}</Text>
                        <Badge
                            label={row.status === 'submitted' ? 'With cashier' : row.shortage_status === 'open' ? 'Short' : 'Received'}
                            tone={row.status === 'submitted' ? 'offline' : row.shortage_status === 'open' ? 'error' : 'synced'}
                        />
                    </View>
                    <Text style={styles.meta}>
                        Cash {formatFcfa(Number(row.expected_cash))}
                        {row.received_cash !== null ? ` · received ${formatFcfa(Number(row.received_cash))}` : ''}
                    </Text>
                </Card>
            ))}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl, backgroundColor: colors.background },
    label: { fontSize: fontSize.xs, fontWeight: '700', color: colors.textSecondary, textTransform: 'uppercase', letterSpacing: 0.3 },
    big: { fontSize: fontSize.display, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.xs },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.sm },
    cell: { width: '30%', flexGrow: 1 },
    cellLabel: { fontSize: fontSize.xs, fontWeight: '600', color: colors.textSecondary },
    cellInput: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.sm,
        paddingHorizontal: spacing.sm,
        paddingVertical: spacing.xs,
        fontSize: fontSize.md,
        color: colors.textPrimary,
        backgroundColor: colors.surface,
    },
    counted: { marginTop: spacing.md, fontSize: fontSize.md, fontWeight: '800', color: colors.status.syncedFg },
    countedOff: { color: colors.danger },
    section: { fontSize: fontSize.md, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.lg },
    row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
    rowTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
});
