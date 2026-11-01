import { useCallback, useState } from 'react';
import { Alert, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { HoldButton } from '../src/components/ui/HoldButton';
import { needsHold } from '../src/utils/holdToConfirm';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { TextInput } from '../src/components/ui/TextInput';
import {
    acceptTransfer,
    closeDay,
    confirmDeposit,
    fetchCash,
    recallTransfer,
    refuseDeposit,
    refuseTransfer,
    settleShortage,
    type CashOverview,
    type CashShortageItem,
    type CashTransferItem,
} from '../src/api/cash';
import { extractErrorMessage } from '../src/api/client';
import { refreshInbox } from '../src/inbox/inboxStore';
import { cashDifference, parseCashAmount } from '../src/utils/cashAmount';
import { formatFcfa, formatRelativeTime } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

interface Field {
    key: string;
    label: string;
    amount?: boolean;
    required?: boolean;
    initial?: string;
    placeholder?: string;
}

interface FormSpec {
    id: string;
    title: string;
    fields: Field[];
    submitLabel: string;
    danger?: boolean;
    /** Wave 6: from 100 000 FCFA the submit button is held, not tapped. */
    holdAmount?: string;
    /** What the count means before it is sent, e.g. "500 FCFA short". */
    hint?: (values: Record<string, string>) => string | null;
    run: (values: Record<string, string>) => Promise<string>;
}

const fcfa = (amount: string | number) => formatFcfa(Math.round(Number(amount)));

function differenceHint(counted: string | undefined, expected: string): string | null {
    const parsed = counted ? parseCashAmount(counted) : null;

    if (parsed === null) {
        return null;
    }

    const difference = cashDifference(parsed, expected);

    return difference === 0 ? 'Matches the book.' : difference < 0 ? `${fcfa(-difference)} short of the book.` : `${fcfa(difference)} over the book.`;
}

/**
 * Cash on the phone (docs/plans/mobile-manager-app.md, wave 4): my till
 * and its day close, handovers to receive, money handed to me, bank
 * deposits to confirm, shortages to settle, and the cash alerts. The same
 * services and rules as the web cash book; online only. Moving money
 * between accounts, statements and branch transfers stay on the website.
 */
export default function CashScreen() {
    const router = useRouter();
    const [cash, setCash] = useState<CashOverview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [form, setForm] = useState<FormSpec | null>(null);
    const [values, setValues] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [openAlert, setOpenAlert] = useState<string | null>(null);

    const load = useCallback(async () => {
        setRefreshing(true);

        try {
            setCash(await fetchCash());
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load the cash. Check your connection.'));
        } finally {
            setRefreshing(false);
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    function openForm(spec: FormSpec) {
        setError(null);
        setForm(spec);
        setValues(Object.fromEntries(spec.fields.map((field) => [field.key, field.initial ?? ''])));
    }

    async function run(task: () => Promise<string>) {
        setBusy(true);
        setError(null);

        try {
            const message = await task();
            setForm(null);
            void refreshInbox();
            Alert.alert('Done', message);
            await load();
        } catch (e) {
            setError(extractErrorMessage(e, 'That did not go through. Try again.'));
        } finally {
            setBusy(false);
        }
    }

    function submitForm(spec: FormSpec) {
        const cleaned: Record<string, string> = {};

        for (const field of spec.fields) {
            const raw = (values[field.key] ?? '').trim();

            if (field.amount) {
                const parsed = parseCashAmount(raw);

                if (parsed === null) {
                    setError(`Write ${field.label.toLowerCase()} in FCFA.`);

                    return;
                }
                cleaned[field.key] = parsed;
            } else {
                if (field.required && raw === '') {
                    setError(`Write ${field.label.toLowerCase()} first.`);

                    return;
                }
                cleaned[field.key] = raw;
            }
        }

        void run(() => spec.run(cleaned));
    }

    function confirmThen(title: string, message: string, label: string, task: () => Promise<string>) {
        Alert.alert(title, message, [
            { text: 'Cancel', style: 'cancel' },
            { text: label, onPress: () => void run(task) },
        ]);
    }

    function renderForm(id: string) {
        if (!form || form.id !== id) {
            return null;
        }

        const hint = form.hint?.(values) ?? null;

        return (
            <View style={styles.form}>
                <Text style={styles.formTitle}>{form.title}</Text>
                {form.fields.map((field) => (
                    <TextInput
                        key={field.key}
                        label={field.label}
                        value={values[field.key] ?? ''}
                        onChangeText={(text) => setValues((current) => ({ ...current, [field.key]: text }))}
                        keyboardType={field.amount ? 'number-pad' : 'default'}
                        multiline={!field.amount}
                        placeholder={field.placeholder}
                    />
                ))}
                {hint ? <Text style={styles.hint}>{hint}</Text> : null}
                {needsHold(form.holdAmount) ? (
                    <>
                        <HoldButton title={`Hold to ${form.submitLabel.toLowerCase()} · ${fcfa(form.holdAmount!)}`} variant={form.danger ? 'danger' : 'primary'} disabled={busy} onConfirm={() => submitForm(form)} />
                        <Button title="Cancel" variant="ghost" onPress={() => setForm(null)} />
                    </>
                ) : (
                    <View style={styles.row}>
                        <Button title="Cancel" variant="ghost" fullWidth={false} style={styles.half} onPress={() => setForm(null)} />
                        <Button title={form.submitLabel} variant={form.danger ? 'danger' : 'primary'} fullWidth={false} style={styles.half} loading={busy} onPress={() => submitForm(form)} />
                    </View>
                )}
            </View>
        );
    }

    if (!cash) {
        return error ? <EmptyState title="Not loaded" subtitle={error} actionLabel="Try again" onAction={() => void load()} /> : <View style={styles.flex} />;
    }

    const till = cash.my_till;
    const closingEarlierDay = till !== null && till.day_to_close !== cash.today;

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load()} />}>
            <Stack.Screen options={{ title: 'Cash' }} />

            {error ? <Text style={styles.error}>{error}</Text> : null}

            {till ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>My till</Text>
                    <Card accentColor={colors.accent.payment}>
                        <Text style={styles.big}>{fcfa(till.balance)}</Text>
                        <Text style={styles.meta}>{till.closed_today ? 'Today is closed.' : closingEarlierDay ? `${till.day_to_close} is not closed yet — close it first.` : 'Open today.'}</Text>
                        {till.can_close && !till.closed_today && form?.id !== 'close' ? (
                            <Button
                                title={closingEarlierDay ? `Close ${till.day_to_close}` : 'Close the day'}
                                variant="secondary"
                                style={styles.topGap}
                                onPress={() =>
                                    openForm({
                                        id: 'close',
                                        title: `Count the till. The book says ${fcfa(till.close_book)}.`,
                                        fields: [
                                            { key: 'counted', label: 'Cash counted', amount: true },
                                            { key: 'note', label: 'Note (optional)' },
                                        ],
                                        submitLabel: 'Close',
                                        hint: (current) => differenceHint(current.counted, till.close_book),
                                        run: (current) => closeDay(till.uuid, current.counted, current.note),
                                    })
                                }
                            />
                        ) : null}
                        {renderForm('close')}
                    </Card>
                </View>
            ) : null}

            {cash.can.receive ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Handovers to receive</Text>
                    {cash.to_receive.length === 0 ? <Text style={styles.meta}>No collector is waiting.</Text> : null}
                    {cash.to_receive.map((handover) => (
                        <Card
                            key={handover.uuid}
                            accentColor={colors.accent.payment}
                            onPress={handover.can_receive ? () => router.push({ pathname: '/cash-receive/[uuid]', params: { uuid: handover.uuid } }) : undefined}
                        >
                            <View style={styles.row}>
                                <Text style={styles.title}>{handover.collector_name ?? 'Collector'}</Text>
                                <Text style={styles.amount}>{fcfa(handover.cash_to_hand_over)}</Text>
                            </View>
                            <Text style={styles.meta}>
                                {handover.number} · {formatRelativeTime(handover.submitted_at)}
                                {Number(handover.expected_mobile) + Number(handover.expected_bank) > 0 ? ` · plus ${fcfa(Number(handover.expected_mobile) + Number(handover.expected_bank))} MoMo / bank to tick` : ''}
                            </Text>
                            {!handover.can_receive ? <Text style={styles.meta}>Your own handover — someone else receives it.</Text> : null}
                        </Card>
                    ))}
                </View>
            ) : null}

            {cash.transfers.length > 0 ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Money waiting</Text>
                    {cash.transfers.map((transfer) => renderTransfer(transfer))}
                </View>
            ) : null}

            {cash.shortages.length > 0 ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Shortages</Text>
                    {cash.shortages.map((shortage) => renderShortage(shortage))}
                </View>
            ) : null}

            {cash.can.view_all ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Cash alerts</Text>
                    {cash.alerts.length === 0 ? <Text style={styles.meta}>Nothing needs attention.</Text> : null}
                    {cash.alerts.map((alert) => (
                        <Card key={alert.key} accentColor={alert.severity === 'danger' ? colors.danger : colors.status.pendingFg} onPress={() => setOpenAlert(openAlert === alert.key ? null : alert.key)}>
                            <View style={styles.row}>
                                <Text style={styles.title}>{alert.title}</Text>
                                <Text style={styles.count}>{alert.items.length}</Text>
                            </View>
                            {openAlert === alert.key ? (
                                <View style={styles.topGap}>
                                    <Text style={styles.meta}>{alert.advice}</Text>
                                    {alert.items.map((item, index) => (
                                        <View key={`${alert.key}-${index}`} style={styles.alertItem}>
                                            <View style={styles.row}>
                                                <Text style={styles.alertLabel}>{item.label}</Text>
                                                {item.amount ? <Text style={styles.alertLabel}>{fcfa(item.amount)}</Text> : null}
                                            </View>
                                            <Text style={styles.meta}>{item.detail}</Text>
                                        </View>
                                    ))}
                                </View>
                            ) : null}
                        </Card>
                    ))}
                </View>
            ) : null}
        </ScrollView>
    );

    function renderTransfer(transfer: CashTransferItem) {
        const isDeposit = transfer.kind === 'deposit';
        const anyFormOpen = form?.id === `t-${transfer.uuid}`;

        return (
            <Card key={transfer.uuid} accentColor={colors.accent.expense}>
                <View style={styles.row}>
                    <Text style={styles.title}>
                        {isDeposit ? `Deposit to ${transfer.to ?? 'the bank'}` : transfer.can_accept ? `From ${transfer.sent_by_name ?? 'a colleague'}` : `To ${transfer.to ?? 'a colleague'}`}
                    </Text>
                    <Text style={styles.amount}>{fcfa(transfer.amount)}</Text>
                </View>
                <Text style={styles.meta}>
                    {transfer.number} · {formatRelativeTime(transfer.sent_at)}
                    {isDeposit ? ` · slip ${transfer.slip_number ?? '—'} · by ${transfer.sent_by_name ?? '—'} · ${transfer.confirmations}/${transfer.required_confirmations ?? 1} confirmed` : ''}
                </Text>
                {transfer.note ? <Text style={styles.meta}>“{transfer.note}”</Text> : null}

                {!anyFormOpen ? (
                    <View style={[styles.row, styles.topGap]}>
                        {transfer.can_accept ? (
                            <>
                                <Button
                                    title="Refuse"
                                    variant="dangerOutline"
                                    fullWidth={false}
                                    style={styles.half}
                                    onPress={() =>
                                        openForm({
                                            id: `t-${transfer.uuid}`,
                                            title: 'The money goes back to the sender.',
                                            fields: [{ key: 'reason', label: 'Reason', required: true }],
                                            submitLabel: 'Refuse',
                                            danger: true,
                                            run: (current) => refuseTransfer(transfer.uuid, current.reason),
                                        })
                                    }
                                />
                                <Button
                                    title="Count & accept"
                                    fullWidth={false}
                                    style={styles.half}
                                    onPress={() =>
                                        openForm({
                                            id: `t-${transfer.uuid}`,
                                            title: 'Count it. Less than sent becomes the sender’s shortage.',
                                            fields: [
                                                { key: 'counted', label: 'Cash counted', amount: true, initial: String(Math.round(Number(transfer.amount))) },
                                                { key: 'note', label: 'Note (optional)' },
                                            ],
                                            submitLabel: 'Accept',
                                            hint: (current) => differenceHint(current.counted, transfer.amount),
                                            run: (current) => acceptTransfer(transfer.uuid, current.counted, current.note),
                                        })
                                    }
                                />
                            </>
                        ) : null}
                        {transfer.can_recall ? (
                            <Button title="Take it back" variant="secondary" onPress={() => confirmThen('Take it back?', `${fcfa(transfer.amount)} returns to your till.`, 'Take back', () => recallTransfer(transfer.uuid))} />
                        ) : null}
                        {transfer.can_refuse_deposit ? (
                            <Button
                                title="Not in the bank"
                                variant="dangerOutline"
                                fullWidth={false}
                                style={styles.half}
                                onPress={() =>
                                    openForm({
                                        id: `t-${transfer.uuid}`,
                                        title: 'What the bank shows. What is missing becomes the depositor’s shortage.',
                                        fields: [
                                            { key: 'found', label: 'Amount found in the bank', amount: true, initial: '0' },
                                            { key: 'reason', label: 'Reason', required: true },
                                        ],
                                        submitLabel: 'Refuse',
                                        danger: true,
                                        run: (current) => refuseDeposit(transfer.uuid, current.found, current.reason),
                                    })
                                }
                            />
                        ) : null}
                        {transfer.can_confirm ? (
                            <Button
                                title="It's in the bank"
                                fullWidth={false}
                                style={styles.half}
                                onPress={() => confirmThen('Confirm the deposit?', `Only if ${fcfa(transfer.amount)} with slip ${transfer.slip_number ?? '—'} shows on the bank statement.`, 'Confirm', () => confirmDeposit(transfer.uuid))}
                            />
                        ) : null}
                    </View>
                ) : null}
                {renderForm(`t-${transfer.uuid}`)}
            </Card>
        );
    }

    function renderShortage(shortage: CashShortageItem) {
        const id = `s-${shortage.uuid}`;

        return (
            <Card key={id} accentColor={colors.danger}>
                <View style={styles.row}>
                    <Text style={styles.title}>{shortage.owner_name ?? 'Someone'}</Text>
                    <Text style={styles.amount}>{fcfa(shortage.amount)}</Text>
                </View>
                <Text style={styles.meta}>
                    {shortage.label} · {formatRelativeTime(shortage.created_at)}
                </Text>
                {shortage.note ? <Text style={styles.meta}>“{shortage.note}”</Text> : null}
                {form?.id !== id && (shortage.can_repay || shortage.can_write_off) ? (
                    <View style={[styles.row, styles.topGap]}>
                        {shortage.can_write_off ? (
                            <Button
                                title="Write off"
                                variant="dangerOutline"
                                fullWidth={false}
                                style={styles.half}
                                onPress={() =>
                                    openForm({
                                        id,
                                        title: 'The company accepts the loss.',
                                        fields: [{ key: 'note', label: 'Reason', required: true }],
                                        submitLabel: 'Write off',
                                        danger: true,
                                        holdAmount: shortage.amount,
                                        run: (current) => settleShortage(shortage, 'written_off', current.note),
                                    })
                                }
                            />
                        ) : null}
                        {shortage.can_repay ? (
                            <Button
                                title="Repaid to me"
                                fullWidth={false}
                                style={styles.half}
                                onPress={() =>
                                    confirmThen('Take the repayment?', `${shortage.owner_name ?? 'They'} hand you ${fcfa(shortage.amount)} now — it goes into your till.`, 'Repaid', () => settleShortage(shortage, 'repaid', ''))
                                }
                            />
                        ) : null}
                    </View>
                ) : null}
                {renderForm(id)}
            </Card>
        );
    }
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, flexWrap: 'wrap' },
    half: { flex: 1, minWidth: 130 },
    topGap: { marginTop: spacing.sm },
    title: { flex: 1, fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    amount: { fontSize: fontSize.md, fontWeight: '800', color: colors.textPrimary },
    big: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary },
    count: { fontSize: fontSize.md, fontWeight: '800', color: colors.danger },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    hint: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    form: { gap: spacing.sm, marginTop: spacing.md, paddingTop: spacing.md, borderTopWidth: 1, borderTopColor: colors.border },
    formTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    alertItem: { paddingVertical: spacing.xs, borderTopWidth: 1, borderTopColor: colors.border, marginTop: spacing.xs },
    alertLabel: { flex: 1, fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
});
