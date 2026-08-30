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
import type {
    ArrearsAdjustmentDirection,
    ArrearsAdjustmentReasonCategory,
    ArrearsAdjustmentTarget,
    CustomerDetailApi,
} from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting' | 'success';

const REASON_LABELS: Record<ArrearsAdjustmentReasonCategory, string> = {
    legacy_migration_error: 'Legacy migration error',
    billing_error: 'Billing error',
    goodwill_service_outage: 'Goodwill — outage',
    bad_debt_writeoff: 'Bad debt write-off',
    credit_clawback: 'Credit clawback',
    other: 'Other',
    credit_correction: 'Credit correction',
    duplicate_credit: 'Duplicate credit',
    migration_credit_error: 'Migration credit error',
};

// Arrears corrections and credit corrections offer different reason menus —
// mirrors resources/tsx/components/customers/ArrearsAdjustmentModal.tsx's
// arrearsReasonOrder / creditReasonOrder exactly. 'other' is shared.
const ARREARS_REASONS: ArrearsAdjustmentReasonCategory[] = [
    'billing_error',
    'goodwill_service_outage',
    'bad_debt_writeoff',
    'credit_clawback',
    'legacy_migration_error',
    'other',
];

const CREDIT_REASONS: ArrearsAdjustmentReasonCategory[] = [
    'credit_correction',
    'duplicate_credit',
    'migration_credit_error',
    'billing_error',
    'other',
];

function currentPeriod(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function toNumberOrNull(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * "Adjust Arrears / Credit" — the mobile REQUEST side of the maker-checker
 * ledger-correction workflow (references/arrears-adjustment.md). Mirrors
 * resources/tsx/components/customers/ArrearsAdjustmentModal.tsx's fields and
 * copy closely: a target toggle (arrears vs loose credit), reason category,
 * direction, target period, amount, a required note, the "this does not
 * record a payment" explanatory note, and the current-balance/balance-after
 * guidance display.
 *
 * TARGET TOGGLE (2026-08-30 addendum, mirroring the identical web addition):
 * a correction lands on EITHER the customer's `total_arrears` OR their loose
 * `credit` figure — the latter is the fallback for the 2026-08 baseline
 * credit corruption (see arrears-adjustment.md). A credit correction touches
 * ONLY the loose credit amount, never prepaid coverage
 * (prepaid_months_remaining / prepaid_rate). "Clear all arrears" / "Clear
 * credit" are pure pre-fill conveniences — direction + amount only, nothing
 * auto-submits, and they change nothing about the maker-checker workflow.
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
 * the current-arrears/credit figures shown as read-only context have to be
 * the real, fresh server-side numbers (fetchCustomerDetail), not stale
 * locally cached values, and the submit itself is a real API call with no
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
 */
export default function AdjustArrearsScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [customer, setCustomer] = useState<CustomerDetailApi | null>(null);
    const phaseRef = useRef<Phase>('loading');

    const [target, setTarget] = useState<ArrearsAdjustmentTarget>('arrears');
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

    const isCredit = target === 'credit';
    const currentArrears = customer?.manuscript?.total_arrears ?? null;
    const currentCredit = customer?.manuscript?.credit ?? null;
    const activeBalance = isCredit ? currentCredit : currentArrears;
    const activeBalanceNumber = toNumberOrNull(activeBalance);

    // Mirrors ArrearsAdjustmentModal.tsx's balanceAfter memo exactly — a
    // simple, display-only guidance figure, not the real credit/arrears-net
    // calculation ManuscriptCalculator performs. That real calculation only
    // ever runs once this request is approved. For an arrears target:
    // 'decrease' writes off, 'increase' corrects up. For a credit target:
    // 'increase' claws credit back (reduces it), 'decrease' grants credit.
    const balanceAfter = useMemo(() => {
        const balance = activeBalanceNumber;
        const amount = Number(amountText);

        if (balance === null || amountText.trim() === '' || !Number.isFinite(amount) || amount <= 0) {
            return null;
        }

        const reduces = isCredit ? direction === 'increase' : direction === 'decrease';

        return reduces ? Math.max(0, balance - amount) : balance + amount;
    }, [activeBalanceNumber, amountText, direction, isCredit]);

    // Switching target resets the fields whose sensible default depends on it
    // — direction (write-off for arrears, claw-back for credit — the 2026-08
    // corruption case), reason category, and the amount. Mirrors the web
    // modal's switchTarget().
    function switchTarget(next: ArrearsAdjustmentTarget) {
        if (next === target) {
            return;
        }

        setTarget(next);
        setDirection(next === 'credit' ? 'increase' : 'decrease');
        setReasonCategory(next === 'credit' ? 'credit_correction' : 'billing_error');
        setAmountText('');
        setErrors((prev) => ({ ...prev, amount: undefined }));
    }

    // "Clear all arrears" / "Clear credit" quick-fill — a faster path to the
    // single most common case (zeroing the whole balance on the active
    // side), matching the web modal's clearActiveBalance(). Pre-fills
    // direction + amount only; reason category and notes are left for the
    // agent (a correction still needs a real justification), and this never
    // submits on its own — Submit Request is still a separate step. Reuses
    // the figure this screen already fetched fresh via fetchCustomerDetail()
    // (see this screen's own class doc); approval-time staleness re-checks
    // re-derive the true server-side value regardless.
    function clearActiveBalance() {
        if (activeBalance === null || activeBalanceNumber === null || activeBalanceNumber <= 0) {
            return;
        }

        setDirection(isCredit ? 'increase' : 'decrease');
        setAmountText(String(activeBalance));
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
                target,
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
                <Stack.Screen options={{ title: 'Adjust Arrears / Credit' }} />
                <ActivityIndicator size="large" color={colors.accent.arrears} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Adjust Arrears / Credit' }} />
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
                <Stack.Screen options={{ title: 'Adjust Arrears / Credit' }} />
                <EmptyState title="Couldn't load this customer" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    if (phase === 'success') {
        return (
            <View style={styles.confirmFlex}>
                <Stack.Screen options={{ title: 'Adjust Arrears / Credit' }} />
                <Badge label="Submitted — pending approval" tone="pending" />
                <Text style={styles.confirmTitle}>{customer.name}</Text>
                <Text style={styles.confirmBody}>
                    {isCredit ? 'Credit' : 'Arrears'} correction request for {formatFcfa(Number(amountText) || 0)} sent.
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
    const reasons = isCredit ? CREDIT_REASONS : ARREARS_REASONS;

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: 'Adjust Arrears / Credit' }} />

            <Card>
                <Text style={styles.customerName}>{customer.name}</Text>
                {customer.zone_name ? <Text style={styles.customerZone}>{customer.zone_name}</Text> : null}
            </Card>

            <Card accentColor={colors.accent.arrears} style={styles.noteCard}>
                <Text style={styles.noteText}>
                    This does not record a payment. No money changes hands here — this requests a correction to{' '}
                    <Text style={styles.noteTextStrong}>{customer.name}</Text>'s {isCredit ? 'credit' : 'arrears'}{' '}
                    balance, which an office reviewer must approve before it takes effect.
                </Text>
            </Card>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>What are you correcting?</Text>
                <View style={styles.chipRow}>
                    {(['arrears', 'credit'] as ArrearsAdjustmentTarget[]).map((option) => {
                        const active = target === option;
                        const figure = option === 'arrears' ? currentArrears : currentCredit;

                        return (
                            <Pressable
                                key={option}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => switchTarget(option)}
                                style={[styles.targetChip, active && styles.chipActive]}
                            >
                                <Text style={[styles.targetChipTitle, active && styles.chipTextActive]}>
                                    {option === 'arrears' ? 'Arrears' : 'Credit'}
                                </Text>
                                <Text style={styles.targetChipFigure}>
                                    {figure === null ? 'no figure yet' : formatFcfa(Number(figure))}
                                </Text>
                            </Pressable>
                        );
                    })}
                </View>
                {isCredit ? (
                    <Text style={styles.helperText}>
                        Corrects only the loose credit figure — not prepaid coverage (prepaid months / rate).
                    </Text>
                ) : null}
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Reason category</Text>
                <View style={styles.chipRow}>
                    {reasons.map((value) => {
                        const active = reasonCategory === value;

                        return (
                            <Pressable
                                key={value}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => setReasonCategory(value)}
                                style={[styles.chip, active && styles.chipActive]}
                            >
                                <Text style={[styles.chipText, active && styles.chipTextActive]}>
                                    {REASON_LABELS[value]}
                                </Text>
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
                            {isCredit ? 'Grant (increase credit)' : 'Decrease (write off)'}
                        </Text>
                    </Pressable>
                    <Pressable
                        accessibilityRole="button"
                        accessibilityState={{ selected: direction === 'increase' }}
                        onPress={() => setDirection('increase')}
                        style={[styles.directionChip, direction === 'increase' && styles.chipActive]}
                    >
                        <Text style={[styles.chipText, direction === 'increase' && styles.chipTextActive]}>
                            {isCredit ? 'Claw back (reduce credit)' : 'Increase (correct up)'}
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

            {activeBalanceNumber !== null && activeBalanceNumber > 0 ? (
                <Pressable
                    accessibilityRole="button"
                    onPress={clearActiveBalance}
                    style={styles.quickFillButton}
                >
                    <Text style={styles.quickFillText}>
                        {isCredit ? 'Clear credit' : 'Clear all arrears'} ({formatFcfa(activeBalanceNumber)})
                    </Text>
                </Pressable>
            ) : null}

            <UiTextInput
                label={`${isCredit ? 'Credit' : 'Arrears'} amount to adjust (FCFA)`}
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

            {activeBalanceNumber !== null ? (
                <Card>
                    <View style={styles.lineRow}>
                        <Text style={styles.lineLabel}>Current {isCredit ? 'credit' : 'arrears'}</Text>
                        <Text style={styles.lineValue}>{formatFcfa(activeBalanceNumber)}</Text>
                    </View>
                    <View style={styles.lineRow}>
                        <Text style={styles.lineLabel}>{isCredit ? 'Credit' : 'Balance'} after</Text>
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
    helperText: { fontSize: fontSize.xs, color: colors.textSecondary },
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
    // Target toggle chip — flex:1 two-up, stacks a bold label over the
    // customer's current figure on that side of the ledger.
    targetChip: {
        flex: 1,
        gap: 2,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    targetChipTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    targetChipFigure: { fontSize: fontSize.xs, color: colors.textSecondary },
    chipActive: {
        borderColor: colors.accent.arrears,
        backgroundColor: '#F3E8FF',
    },
    // "Clear all arrears" / "Clear credit" quick-fill chip — same
    // border/tint pattern as chipActive above (violet accent), 48dp floor
    // since this is a secondary convenience action, not the screen's
    // primary CTA.
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
