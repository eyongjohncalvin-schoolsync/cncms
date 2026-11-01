import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Button } from '../../src/components/ui/Button';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { TextInput } from '../../src/components/ui/TextInput';
import { fetchHandover, receiveHandover, type HandoverDetail } from '../../src/api/cash';
import { extractErrorMessage } from '../../src/api/client';
import { refreshInbox } from '../../src/inbox/inboxStore';
import { cashDifference, parseCashAmount } from '../../src/utils/cashAmount';
import { paymentMethodLabel } from '../../src/utils/paymentCalc';
import { formatFcfa } from '../../src/utils/format';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../src/theme/tokens';

const fcfa = (amount: string | number) => formatFcfa(Math.round(Number(amount)));

/**
 * Receiving a collector's handover on the phone (mobile wave 4): count the
 * cash, tick each MoMo / bank payment seen on the statement, receive.
 * Anything short stays open on the collector; payments not ticked stay
 * owed — exactly as at the web cashier's desk (RemittanceService::receive()).
 */
export default function ReceiveHandoverScreen() {
    const router = useRouter();
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const [handover, setHandover] = useState<HandoverDetail | null>(null);
    const [counted, setCounted] = useState('');
    const [note, setNote] = useState('');
    const [ticked, setTicked] = useState<number[]>([]);
    const [bank, setBank] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const detail = await fetchHandover(uuid);
            setHandover(detail);
            setBank(detail.bank_accounts.length === 1 ? detail.bank_accounts[0].uuid : null);
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load this handover. Check your connection.'));
        }
    }, [uuid]);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    if (!handover) {
        return error ? (
            <EmptyState title="Not loaded" subtitle={error} actionLabel="Try again" onAction={() => void load()} />
        ) : (
            <View style={styles.loading}>
                <ActivityIndicator />
            </View>
        );
    }

    const nonCash = handover.lines.filter((line) => line.method !== 'cash');
    const parsed = parseCashAmount(counted);
    const difference = parsed !== null ? cashDifference(parsed, handover.cash_to_hand_over) : null;
    const tickedBank = nonCash.some((line) => line.method === 'bank' && ticked.includes(line.id));

    function toggle(id: number) {
        setTicked((current) => (current.includes(id) ? current.filter((existing) => existing !== id) : [...current, id]));
    }

    async function receive() {
        if (parsed === null) {
            setError('Write the cash you counted, in FCFA.');

            return;
        }

        if (tickedBank && bank === null) {
            setError('Choose the bank account the bank payments went into.');

            return;
        }

        setBusy(true);
        setError(null);

        try {
            const message = await receiveHandover(uuid, parsed, ticked, tickedBank ? bank : null, note.trim());
            void refreshInbox();
            Alert.alert('Received', message);
            router.back();
        } catch (e) {
            setError(extractErrorMessage(e, 'That did not go through. Try again.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: handover.number }} />

            <View style={styles.summary}>
                <Text style={styles.title}>{handover.collector_name ?? 'Collector'}</Text>
                <Text style={styles.meta}>Cash to hand over</Text>
                <Text style={styles.big}>{fcfa(handover.cash_to_hand_over)}</Text>
                <Text style={styles.meta}>
                    Collected {fcfa(handover.expected_cash)} cash
                    {Number(handover.bons_total) > 0 ? `, less ${fcfa(handover.bons_total)} of bons paid out` : ''} · they declared {fcfa(handover.declared_cash)}
                </Text>
                {handover.collector_note ? <Text style={styles.meta}>“{handover.collector_note}”</Text> : null}
            </View>

            {handover.bons.length > 0 ? (
                <View style={styles.block}>
                    <Text style={styles.blockTitle}>Bons paid out of it</Text>
                    {handover.bons.map((bon) => (
                        <Text key={bon.number} style={styles.meta}>
                            {bon.number} · {bon.payee_name ?? '—'} · {fcfa(bon.amount)}
                        </Text>
                    ))}
                </View>
            ) : null}

            <TextInput label="Cash you counted" value={counted} onChangeText={setCounted} keyboardType="number-pad" placeholder={String(Math.round(Number(handover.cash_to_hand_over)))} />
            {difference !== null ? (
                <Text style={[styles.hint, difference < 0 && styles.short]}>
                    {difference === 0 ? 'Matches.' : difference < 0 ? `${fcfa(-difference)} short — it stays open on the collector.` : `${fcfa(difference)} over — kept as the collector’s credit.`}
                </Text>
            ) : null}

            {nonCash.length > 0 ? (
                <View style={styles.block}>
                    <Text style={styles.blockTitle}>MoMo / bank payments</Text>
                    <Text style={styles.meta}>Tick each one you can see on the statement. The ones not ticked stay owed by the collector.</Text>
                    {nonCash.map((line) => {
                        const on = ticked.includes(line.id);

                        return (
                            <Pressable key={line.id} accessibilityRole="checkbox" accessibilityState={{ checked: on }} onPress={() => toggle(line.id)} style={[styles.line, on && styles.lineOn]}>
                                <View style={styles.row}>
                                    <Text style={styles.lineTitle}>
                                        {on ? '☑ ' : '☐ '}
                                        {line.customer_name ?? 'Customer'}
                                    </Text>
                                    <Text style={styles.lineTitle}>{fcfa(line.amount)}</Text>
                                </View>
                                <Text style={styles.meta}>
                                    {paymentMethodLabel(line.method)} · ref {line.reference ?? '—'}
                                </Text>
                            </Pressable>
                        );
                    })}
                </View>
            ) : null}

            {tickedBank && handover.bank_accounts.length > 1 ? (
                <View style={styles.block}>
                    <Text style={styles.blockTitle}>Into which bank account?</Text>
                    {handover.bank_accounts.map((account) => (
                        <Pressable key={account.uuid} accessibilityRole="radio" accessibilityState={{ checked: bank === account.uuid }} onPress={() => setBank(account.uuid)} style={[styles.line, bank === account.uuid && styles.lineOn]}>
                            <Text style={styles.lineTitle}>{account.name}</Text>
                        </Pressable>
                    ))}
                </View>
            ) : null}

            <TextInput label="Note (optional)" value={note} onChangeText={setNote} multiline />

            {error ? <Text style={styles.error}>{error}</Text> : null}

            <Button title="Receive into my till" size="large" loading={busy} disabled={!handover.can_receive} onPress={() => void receive()} />
            {!handover.can_receive ? <Text style={styles.meta}>You can’t receive this one — it’s yours, or already received.</Text> : null}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    loading: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    summary: { gap: spacing.xs },
    title: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    big: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary },
    hint: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    short: { color: colors.danger },
    block: { gap: spacing.sm },
    blockTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    line: { minHeight: touchTarget.floor, padding: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, gap: 2 },
    lineOn: { borderColor: colors.accent.payment, borderWidth: 2, backgroundColor: colors.status.verifiedBg },
    lineTitle: { flex: 1, fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
});
