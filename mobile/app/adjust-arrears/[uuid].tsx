import { useCallback, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { fetchCustomerDetail } from '../../src/api/customers';
import { requestArrearsAdjustment } from '../../src/api/arrearsAdjustments';
import { isNetworkError, extractErrorMessage } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { TextInput as UiTextInput } from '../../src/components/ui/TextInput';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { validateArrearsAdjustmentForm, type ArrearsAdjustmentFormErrors } from '../../src/utils/validation';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';
import type { ArrearsAdjustmentDirection, ArrearsAdjustmentReasonCategory, CustomerDetailApi } from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting' | 'success';

const REASON_CATEGORIES: Array<{ value: ArrearsAdjustmentReasonCategory; label: string }> = [
    { value: 'billing_error', label: 'Billing error' },
    { value: 'goodwill_service_outage', label: 'Goodwill — outage' },
    { value: 'bad_debt_writeoff', label: 'Bad debt write-off' },
    { value: 'credit_clawback', label: 'Credit clawback' },
    { value: 'legacy_migration_error', label: 'Legacy migration error' },
    { value: 'other', label: 'Other' },
];

function currentPeriod(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * "Adjust Arrears" — the mobile REQUEST side of the maker-checker write-off
 * workflow (references/arrears-adjustment.md). Mirrors
 * resources/tsx/components/customers/ArrearsAdjustmentModal.tsx's fields and
 * copy closely: reason category, direction, target period, amount, a
 * required note, the "this does not record a payment" explanatory note, and
 * the same current-balance/balance-after guidance display.
 *
 * REQUEST-ONLY, ON PURPOSE — there is no approve/reject UI anywhere in this
 * screen or app. ArrearsAdjustmentPolicy::create() is ungated for all 5
 * tenant roles (confirmed by reading the real policy before building this),
 * so unlike Reconnect & Pay (super/admin/manager only) or Disconnect (a
 * narrower role set), this screen has no "not authorized" branch — every
 * signed-in agent may submit a request. The two-approver review/approve
 * workflow stays office/web-only (the Audit Log page's "Arrears
 * Adjustments" sub-tab) — matching this app's established "mobile creates,
 * web reviews" split already used for payments/expenditures/complaints/
 * disconnections.
 *
 * ONLINE-ONLY, same reasoning as reconnect/[uuid].tsx and disconnect/[uuid].tsx:
 * the current-arrears figure shown as read-only context has to be the real,
 * fresh server-side number (fetchCustomerDetail), not a stale locally
 * cached value, and the submit itself is a real API call with no
 * local_uuid idempotency support — so this is not queued through the
 * offline /sync/push protocol.
 *
 * Success is a dedicated in-screen confirmation view, matching this app's
 * established "success screens, not native Alert popups" pattern — but
 * deliberately uses Badge tone="pending" (not "synced"/green): unlike
 * Reconnect/Disconnect, which apply an immediate, confirmed-on-server
 * change, submitting this request does NOT change the customer's balance
 * yet — it only starts the maker-checker review. The copy is explicit that
 * office approval is still needed before anything takes effect.
 *
 * The "Clear all arrears" chip above the amount field (2026-08-28 addendum,
 * mirroring the identical web addition on ArrearsAdjustmentModal.tsx) is a
 * pure pre-fill convenience — direction+amount only, nothing auto-submits,
 * and it changes nothing about the maker-checker workflow above. See
 * clearAllArrears() below.
 */
export default function AdjustArrearsScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [customer, setCustomer] = useState<CustomerDetailApi | null>(null);
    const phaseRef = useRef<Phase>('loading');

    const [reasonCategory, setReasonCategory] = useState<ArrearsAdjustmentReasonCategory>('billing_error');
    const [direction, setDirection] = useState<ArrearsAdjustmentDirection>('decrease');
    const [targetPeriod, setTargetPeriod] = useState(currentPeriod());
    const [amountText, setAmountText] = useState('');
    const [reasonNote, setReasonNote] = useState('');
    const [errors, setErrors] = useState<ArrearsAdjustmentFormErrors>({});

    const load = useCallback(() => {
        if (!uuid) {
            return;
        }

        if (!getSyncState().isOnline) {
            phaseRef.current = 'offline';
            setPhase('offline');
            return;
        }

        phaseRef.current = 'loading';
        setPhase('loading');
        setErrorMessage(null);

        fetchCustomerDetail(uuid)
            .then((response) => {
                setCustomer(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load this customer."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, [uuid]);

    // Same "retry automatically once connectivity returns" behavior as
    // Reconnect & Pay / Disconnect.
    useFocusEffect(
        useCallback(() => {
            load();

            return subscribeSyncState(() => {
                if (phaseRef.current === 'offline' && getSyncState().isOnline) {
                    load();
                }
            });
        }, [load]),
    );

    const currentBalance = customer?.manuscript?.total_arrears ?? null;

    // Mirrors ArrearsAdjustmentModal.tsx's balanceAfter memo exactly — a
    // simple, display-only guidance figure, not the real credit/arrears-net
    // calculation ManuscriptCalculator performs. That real calculation only
    // ever runs once this request is approved.
    const balanceAfter = useMemo(() => {
        if (currentBalance === null || amountText.trim() === '') {
            return null;
        }

        const balance = Number(currentBalance);
        const amount = Number(amountText);

        if (!Number.isFinite(balance) || !Number.isFinite(amount) || amount <= 0) {
            return null;
        }

        return direction === 'decrease' ? Math.max(0, balance - amount) : balance + amount;
    }, [currentBalance, amountText, direction]);

    // "Clear all arrears" quick-fill — a faster path to the single most
    // common case (writing off the customer's ENTIRE current balance),
    // matching resources/tsx/components/customers/ArrearsAdjustmentModal.tsx's
    // identical addition. Pre-fills direction+amount only; reason category
    // and notes are left for the agent to fill in themselves (a write-off
    // still needs a real justification), and this never submits on its own
    // — Submit Request below is still a separate, explicit step. Reuses the
    // `currentBalance` this screen already fetched fresh via
    // fetchCustomerDetail() (see this screen's own class doc) rather than a
    // second live fetch, since that figure is already the real current
    // server-side number this screen is built around.
    function clearAllArrears() {
        if (currentBalance === null) {
            return;
        }

        setDirection('decrease');
        setAmountText(currentBalance);
        setErrors((prev) => ({ ...prev, amount: undefined }));
    }

    async function handleSubmit() {
        if (!uuid) {
            return;
        }

        const result = validateArrearsAdjustmentForm({ targetPeriod, amountText, reasonNote });

        if (!result.valid) {
            setErrors(result.errors);
            return;
        }

        setErrors({});
        phaseRef.current = 'submitting';
        setPhase('submitting');

        try {
            await requestArrearsAdjustment({
                customer_uuid: uuid,
                target_period: targetPeriod.trim(),
                direction,
                amount: result.amount.toFixed(2),
                reason_category: reasonCategory,
                reason_note: reasonNote.trim(),
            });

            phaseRef.current = 'success';
            setPhase('success');
        } catch (error) {
            phaseRef.current = 'ready';
            setPhase('ready');

            const fieldErrors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
                ?.data?.errors;

            if (fieldErrors) {
                setErrors({
                    targetPeriod: fieldErrors.target_period?.[0],
                    amount: fieldErrors.amount?.[0],
                    reasonNote: fieldErrors.reason_note?.[0],
                });
            }

            setErrorMessage(extractErrorMessage(error, "Couldn't submit this request."));
        }
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Adjust Arrears' }} />
                <ActivityIndicator size="large" color={colors.accent.arrears} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Adjust Arrears' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="This needs the customer's real current balance from the server and can't be prepared offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error' || !customer) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Adjust Arrears' }} />
                <EmptyState title="Couldn't load this customer" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    if (phase === 'success') {
        return (
            <View style={styles.confirmFlex}>
                <Stack.Screen options={{ title: 'Adjust Arrears' }} />
                <Badge label="Submitted — pending approval" tone="pending" />
                <Text style={styles.confirmTitle}>{customer.name}</Text>
                <Text style={styles.confirmBody}>
                    {direction === 'decrease' ? 'Write-off' : 'Correction'} request for {formatFcfa(Number(amountText) || 0)} sent.
                </Text>
                <Text style={styles.confirmHint}>
                    This does not change the customer's balance yet — it needs office approval before it takes
                    effect. You'll be notified once it's reviewed.
                </Text>
                <Button title="Done" size="large" onPress={() => router.back()} style={styles.confirmButton} />
            </View>
        );
    }

    const submitting = phase === 'submitting';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: 'Adjust Arrears' }} />

            <Card>
                <Text style={styles.customerName}>{customer.name}</Text>
                {customer.zone_name ? <Text style={styles.customerZone}>{customer.zone_name}</Text> : null}
            </Card>

            <Card accentColor={colors.accent.arrears} style={styles.noteCard}>
                <Text style={styles.noteText}>
                    This does not record a payment. No money changes hands here — this requests a correction to{' '}
                    <Text style={styles.noteTextStrong}>{customer.name}</Text>'s arrears balance, which an office
                    reviewer must approve before it takes effect.
                </Text>
            </Card>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Reason category</Text>
                <View style={styles.chipRow}>
                    {REASON_CATEGORIES.map((option) => {
                        const active = reasonCategory === option.value;

                        return (
                            <Pressable
                                key={option.value}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => setReasonCategory(option.value)}
                                style={[styles.chip, active && styles.chipActive]}
                            >
                                <Text style={[styles.chipText, active && styles.chipTextActive]}>{option.label}</Text>
                            </Pressable>
                        );
                    })}
                </View>
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Direction</Text>
                <View style={styles.chipRow}>
                    <Pressable
                        accessibilityRole="button"
                        accessibilityState={{ selected: direction === 'decrease' }}
                        onPress={() => setDirection('decrease')}
                        style={[styles.directionChip, direction === 'decrease' && styles.chipActive]}
                    >
                        <Text style={[styles.chipText, direction === 'decrease' && styles.chipTextActive]}>
                            Decrease (write off)
                        </Text>
                    </Pressable>
                    <Pressable
                        accessibilityRole="button"
                        accessibilityState={{ selected: direction === 'increase' }}
                        onPress={() => setDirection('increase')}
                        style={[styles.directionChip, direction === 'increase' && styles.chipActive]}
                    >
                        <Text style={[styles.chipText, direction === 'increase' && styles.chipTextActive]}>
                            Increase (correct up)
                        </Text>
                    </Pressable>
                </View>
            </View>

            <UiTextInput
                label="Target period (YYYY-MM)"
                placeholder={currentPeriod()}
                value={targetPeriod}
                onChangeText={(text) => {
                    setTargetPeriod(text);
                    setErrors((prev) => ({ ...prev, targetPeriod: undefined }));
                }}
                keyboardType="numbers-and-punctuation"
                autoCapitalize="none"
                autoCorrect={false}
                error={errors.targetPeriod}
            />

            {currentBalance !== null && Number(currentBalance) > 0 ? (
                <Pressable
                    accessibilityRole="button"
                    onPress={clearAllArrears}
                    style={styles.quickFillButton}
                >
                    <Text style={styles.quickFillText}>Clear all arrears ({formatFcfa(Number(currentBalance))})</Text>
                </Pressable>
            ) : null}

            <UiTextInput
                label="Arrears amount to adjust (FCFA)"
                placeholder="0.00"
                value={amountText}
                onChangeText={(text) => {
                    setAmountText(text);
                    setErrors((prev) => ({ ...prev, amount: undefined }));
                }}
                keyboardType="decimal-pad"
                error={errors.amount}
            />

            <UiTextInput
                label="Notes (required)"
                placeholder="Explain why this correction is needed — this is a permanent audit record."
                value={reasonNote}
                onChangeText={(text) => {
                    setReasonNote(text);
                    setErrors((prev) => ({ ...prev, reasonNote: undefined }));
                }}
                multiline
                numberOfLines={4}
                style={styles.noteInput}
                error={errors.reasonNote}
            />

            {currentBalance !== null ? (
                <Card>
                    <View style={styles.lineRow}>
                        <Text style={styles.lineLabel}>Current balance</Text>
                        <Text style={styles.lineValue}>{formatFcfa(Number(currentBalance))}</Text>
                    </View>
                    <View style={styles.lineRow}>
                        <Text style={styles.lineLabel}>Balance after</Text>
                        <Text style={styles.lineValue}>{balanceAfter === null ? '—' : formatFcfa(balanceAfter)}</Text>
                    </View>
                    <Text style={styles.readOnlyHint}>Guidance only — the real figure is set by the billing engine once approved.</Text>
                </Card>
            ) : null}

            {errorMessage && phase === 'ready' ? (
                <View style={styles.submitErrorBox}>
                    <Text style={styles.submitErrorText}>{errorMessage}</Text>
                </View>
            ) : null}

            <Button
                title={submitting ? 'Submitting…' : 'Submit Request'}
                size="large"
                loading={submitting}
                disabled={submitting}
                onPress={handleSubmit}
                style={styles.submitButton}
            />
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    confirmFlex: {
        flex: 1,
        backgroundColor: colors.background,
        alignItems: 'center',
        justifyContent: 'center',
        padding: spacing.xl,
        gap: spacing.sm,
    },
    confirmTitle: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.md },
    confirmBody: { fontSize: fontSize.lg, fontWeight: '600', color: colors.textPrimary, textAlign: 'center' },
    confirmHint: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center', marginBottom: spacing.lg },
    confirmButton: { width: '100%' },
    customerName: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    customerZone: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    // Light violet tint scoped locally to this screen (matches
    // log-complaint.tsx's identical "local literal tint, not a shared
    // token" convention for its own categoryChipActive background).
    noteCard: { backgroundColor: '#F3E8FF' },
    noteText: { fontSize: fontSize.sm, color: colors.textPrimary },
    noteTextStrong: { fontWeight: '700' },
    section: { gap: spacing.sm },
    sectionLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    chip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    directionChip: {
        flex: 1,
        alignItems: 'center',
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: {
        borderColor: colors.accent.arrears,
        backgroundColor: '#F3E8FF',
    },
    // "Clear all arrears" quick-fill chip — same border/tint pattern as
    // chipActive above (violet accent), 48dp floor since this is a
    // secondary convenience action, not the screen's primary CTA.
    quickFillButton: {
        alignSelf: 'flex-start',
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        paddingHorizontal: spacing.md,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.accent.arrears,
        backgroundColor: '#F3E8FF',
    },
    quickFillText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.arrears },
    chipText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    chipTextActive: { color: colors.accent.arrears },
    noteInput: { minHeight: 88, textAlignVertical: 'top', paddingTop: spacing.md },
    lineRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: spacing.xs },
    lineLabel: { fontSize: fontSize.md, color: colors.textSecondary },
    lineValue: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    readOnlyHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.sm },
    submitErrorBox: { backgroundColor: colors.status.errorBg, borderRadius: radius.md, padding: spacing.md },
    submitErrorText: { fontSize: fontSize.sm, color: colors.status.errorFg },
    submitButton: { marginTop: spacing.sm },
});
