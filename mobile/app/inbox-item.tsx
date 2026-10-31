import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { TextInput } from '../src/components/ui/TextInput';
import { EmptyState } from '../src/components/ui/EmptyState';
import { getInboxState, refreshInbox, removeInboxItem, subscribeInboxState } from '../src/inbox/inboxStore';
import {
    approveArrearsAdjustment,
    approveVoucher,
    assignComplaint,
    declineReversal,
    fetchTechnicians,
    rejectArrearsAdjustment,
    rejectVoucher,
    reversePayment,
    type InboxItem,
    type Technician,
} from '../src/api/inbox';
import { extractErrorMessage } from '../src/api/client';
import { formatFcfa, formatRelativeTime } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';

const UNDO_SECONDS = 4;

interface PendingDecision {
    label: string;
    run: () => Promise<string>;
}

const TYPE_LABEL: Record<string, string> = {
    voucher: 'Voucher',
    arrears_adjustment: 'Arrears adjustment',
    reversal_request: 'Payment reversal request',
    complaint: 'Complaint',
};

/**
 * One inbox decision (docs/plans/mobile-manager-app.md, wave 1). Every
 * button starts a short countdown with Undo before anything is sent (the
 * agreed design's "C" undo), then calls the same server rule as the web;
 * the server's own sentence is shown as the result.
 */
export default function InboxItemScreen() {
    const router = useRouter();
    const { key } = useLocalSearchParams<{ key: string }>();
    const inbox = useSyncExternalStore(subscribeInboxState, getInboxState);
    // Kept once found: the item leaves the store the moment it is decided.
    const [item] = useState<InboxItem | undefined>(() => getInboxState().items.find((candidate) => candidate.key === key));
    const [text, setText] = useState('');
    const [refund, setRefund] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState<PendingDecision | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(UNDO_SECONDS);
    const [sending, setSending] = useState(false);
    const [technicians, setTechnicians] = useState<Technician[] | null>(null);
    const [chosen, setChosen] = useState<Technician | null>(null);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);
    // Guards the one send per countdown (an effect may run more than once).
    const sent = useRef(false);

    // The countdown ran out without Undo: send, exactly once.
    useEffect(() => {
        if (pending === null || secondsLeft > 0 || sent.current) {
            return;
        }

        sent.current = true;
        if (timer.current) {
            clearInterval(timer.current);
        }
        void send(pending);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pending, secondsLeft]);

    useEffect(() => {
        if (item?.type === 'complaint' && item.uuid) {
            fetchTechnicians(item.uuid)
                .then(setTechnicians)
                .catch((e: unknown) => setError(extractErrorMessage(e, 'Could not load the technicians.')));
        }
    }, [item]);

    useEffect(() => () => {
        if (timer.current) {
            clearInterval(timer.current);
        }
    }, []);

    if (!item || !item.uuid) {
        return <EmptyState title="Already handled" subtitle="This item is no longer waiting for you." actionLabel="Back to the inbox" onAction={() => router.back()} />;
    }

    const uuid = item.uuid;
    const amount = item.amount !== null ? Number(item.amount) : null;
    const needsText = (label: string) => setError(`Write ${label} first.`);

    function start(decision: PendingDecision) {
        setError(null);
        sent.current = false;
        setSecondsLeft(UNDO_SECONDS);
        setPending(decision);

        timer.current = setInterval(() => setSecondsLeft((left) => Math.max(0, left - 1)), 1000);
    }

    function undo() {
        if (timer.current) {
            clearInterval(timer.current);
        }
        setPending(null);
    }

    async function send(decision: PendingDecision) {
        setSending(true);

        try {
            const message = await decision.run();
            removeInboxItem(item!.key);
            void refreshInbox();
            Alert.alert('Done', message);
            router.back();
        } catch (e) {
            setError(extractErrorMessage(e, 'That did not go through. Try again.'));
            setPending(null);
        } finally {
            setSending(false);
        }
    }

    const reason = text.trim();

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: TYPE_LABEL[item.type] ?? 'Decision' }} />

            <View style={styles.summary}>
                <Text style={styles.title}>{item.title}</Text>
                {amount !== null ? <Text style={styles.amount}>{(amount < 0 ? '−' : '') + formatFcfa(Math.abs(amount))}</Text> : null}
                <Text style={styles.subtitle}>{item.subtitle}</Text>
                {item.created_at ? <Text style={styles.meta}>Asked {formatRelativeTime(item.created_at)}</Text> : null}
            </View>

            {inbox.offline ? <Text style={styles.warning}>No connection right now — a decision needs internet to go through.</Text> : null}

            {item.type === 'complaint' ? (
                <View style={styles.block}>
                    <Text style={styles.blockTitle}>Give it to</Text>
                    {technicians === null && !error ? <ActivityIndicator /> : null}
                    {technicians?.length === 0 ? <Text style={styles.subtitle}>Nobody in this branch resolves complaints yet. Set technicians on the website.</Text> : null}
                    {technicians?.map((technician) => (
                        <Pressable
                            key={technician.uuid}
                            accessibilityRole="radio"
                            accessibilityState={{ checked: chosen?.uuid === technician.uuid }}
                            onPress={() => setChosen(technician)}
                            style={[styles.technician, chosen?.uuid === technician.uuid && styles.technicianChosen]}
                        >
                            <Text style={styles.technicianName}>{technician.name}</Text>
                            <Text style={styles.subtitle}>
                                {technician.reason === 'covers' ? 'Covers this zone' : technician.reason === 'anywhere' ? 'Works anywhere' : 'Other zones'} · {technician.open} open
                            </Text>
                        </Pressable>
                    ))}
                </View>
            ) : (
                <View style={styles.block}>
                    <TextInput
                        label={item.type === 'reversal_request' ? 'Reason or note (needed)' : 'Note (needed to reject)'}
                        value={text}
                        onChangeText={setText}
                        multiline
                        placeholder={item.type === 'voucher' ? 'e.g. Keep the receipt' : undefined}
                    />
                    {item.type === 'reversal_request' && item.actions.includes('reverse') ? (
                        <View style={styles.switchRow}>
                            <Text style={styles.switchLabel}>Refund the customer (a refund bon is raised)</Text>
                            <Switch value={refund} onValueChange={setRefund} accessibilityLabel="Refund the customer" />
                        </View>
                    ) : null}
                </View>
            )}

            {error ? <Text style={styles.error}>{error}</Text> : null}

            {pending ? (
                <View style={styles.pending} accessibilityLiveRegion="polite">
                    <Text style={styles.pendingText}>{sending ? `${pending.label}…` : `${pending.label} in ${secondsLeft}s`}</Text>
                    {!sending ? (
                        <Pressable accessibilityRole="button" onPress={undo} style={styles.undo}>
                            <Text style={styles.undoText}>Undo</Text>
                        </Pressable>
                    ) : (
                        <ActivityIndicator color={colors.textInverse} />
                    )}
                </View>
            ) : (
                <View style={styles.actions}>
                    {item.type === 'voucher' ? (
                        <>
                            <Button title="Reject" variant="dangerOutline" fullWidth={false} style={styles.half} onPress={() => (reason ? start({ label: 'Rejecting', run: () => rejectVoucher(uuid, reason) }) : needsText('the reason'))} />
                            <Button title="Approve" fullWidth={false} style={styles.half} onPress={() => start({ label: 'Approving', run: () => approveVoucher(uuid, reason || undefined) })} />
                        </>
                    ) : null}
                    {item.type === 'arrears_adjustment' ? (
                        <>
                            <Button title="Reject" variant="dangerOutline" fullWidth={false} style={styles.half} onPress={() => (reason ? start({ label: 'Rejecting', run: () => rejectArrearsAdjustment(uuid, reason) }) : needsText('the reason'))} />
                            <Button title="Approve" fullWidth={false} style={styles.half} onPress={() => start({ label: 'Approving', run: () => approveArrearsAdjustment(uuid) })} />
                        </>
                    ) : null}
                    {item.type === 'reversal_request' ? (
                        <>
                            <Button title="Decline" variant="dangerOutline" fullWidth={false} style={styles.half} onPress={() => (reason.length >= 3 ? start({ label: 'Declining', run: () => declineReversal(uuid, reason) }) : needsText('a note for the collector'))} />
                            {item.actions.includes('reverse') ? (
                                <Button title="Reverse payment" fullWidth={false} style={styles.half} onPress={() => (reason.length >= 3 ? start({ label: 'Reversing', run: () => reversePayment(uuid, reason, refund) }) : needsText('the reason'))} />
                            ) : null}
                        </>
                    ) : null}
                    {item.type === 'complaint' ? (
                        <Button
                            title={chosen ? `Assign to ${chosen.name}` : 'Choose a technician'}
                            disabled={!chosen}
                            size="large"
                            onPress={() => chosen && start({ label: `Assigning to ${chosen.name}`, run: () => assignComplaint(uuid, chosen.uuid, chosen.name) })}
                        />
                    ) : null}
                </View>
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    summary: { gap: spacing.xs },
    title: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    amount: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary },
    subtitle: { fontSize: fontSize.sm, color: colors.textSecondary },
    meta: { fontSize: fontSize.xs, color: colors.textSecondary },
    warning: { fontSize: fontSize.sm, color: colors.status.offlineFg, backgroundColor: colors.status.offlineBg, padding: spacing.sm, borderRadius: radius.md },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    block: { gap: spacing.sm },
    blockTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    technician: { minHeight: touchTarget.floor, padding: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, gap: 2 },
    technicianChosen: { borderColor: colors.accent.payment, borderWidth: 2, backgroundColor: colors.status.verifiedBg },
    technicianName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: touchTarget.floor },
    switchLabel: { flex: 1, fontSize: fontSize.sm, color: colors.textPrimary },
    actions: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    half: { flex: 1, minWidth: 140 },
    pending: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.textPrimary, borderRadius: radius.md, padding: spacing.md, minHeight: touchTarget.primary },
    pendingText: { color: colors.textInverse, fontSize: fontSize.md, fontWeight: '700' },
    undo: { minHeight: touchTarget.floor, minWidth: 72, alignItems: 'center', justifyContent: 'center' },
    undoText: { color: '#FCD34D', fontSize: fontSize.md, fontWeight: '800' },
});
