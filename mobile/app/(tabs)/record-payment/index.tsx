import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    ActivityIndicator,
    Alert,
    Image,
    Keyboard,
    Pressable,
    ScrollView,
    StyleSheet,
    Text,
    View,
} from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Card } from '../../../src/components/ui/Card';
import { Button } from '../../../src/components/ui/Button';
import { Badge, type BadgeTone } from '../../../src/components/ui/Badge';
import { TextInput as UiTextInput } from '../../../src/components/ui/TextInput';
import { EmptyState } from '../../../src/components/ui/EmptyState';
import { colors } from '../../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../../src/theme/tokens';
import { formatFcfa } from '../../../src/utils/format';
import { calculateGuideAmount, validatePaymentForm, type PaymentFrequency } from '../../../src/utils/paymentCalc';
import { getAllCustomers, getCustomerByUuid } from '../../../src/db/customers';
import { getLastPaymentForCustomer, getPaymentByLocalUuid, insertLocalPayment } from '../../../src/db/payments';
import type { LocalCustomer, LocalPayment } from '../../../src/types/db';
import { syncManager } from '../../../src/sync/SyncManager';

const FREQUENCIES: Array<{ value: PaymentFrequency; label: string }> = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'months', label: 'Multi-month' },
    { value: 'yearly', label: 'Yearly' },
];

/** Matches App\Http\Requests\StorePaymentRequest's guard exactly — a
 * disconnected/suspended customer needs Reconnect & Pay first, not a plain
 * payment. `passive` is deliberately NOT included here; it stays payable. */
const UNPAYABLE_STATUSES = new Set(['disconnected', 'suspended']);

/**
 * Mirrors Customer Detail's PAYMENT_SYNC_BADGE map
 * (app/(tabs)/customers/[uuid].tsx) for the two states this screen's
 * confirmation can show, so the amber/green vocabulary stays identical
 * across screens. Kept local rather than extracted to a shared module —
 * it's two entries, not worth the indirection.
 *
 * This pairing IS the single most load-bearing visual rule on this screen
 * (mobile-app-react-native.md §5): 'queued' is unconditionally what's shown
 * the instant a payment is written locally, regardless of connectivity —
 * never assume an instant sync. 'synced' only replaces it if a poll
 * confirms the write actually round-tripped to the server before the
 * confirmation view's own timer fires.
 */
const CONFIRM_BADGE: Record<'queued' | 'synced', { label: string; tone: BadgeTone }> = {
    queued: { label: 'Saved · will sync', tone: 'offline' },
    synced: { label: 'Synced', tone: 'synced' },
};

// How long the confirmation state stays on screen before auto-navigating
// back to the Customer List, and how often it polls this device's own
// local row for a near-instant queued->synced transition within that
// window. Never blocks navigation on the poll settling either way.
const CONFIRM_DISPLAY_MS = 2800;
const CONFIRM_POLL_MS = 500;

type Stage = 'form' | 'confirming';

function frequencyLabel(freq: PaymentFrequency): string {
    return freq === 'monthly' ? 'Monthly' : freq === 'months' ? 'Multi-month' : 'Yearly';
}

function verificationLabel(status: LocalPayment['verification_status']): string {
    return status === 'pending' ? 'Pending' : status === 'verified' ? 'Verified' : 'Rejected';
}

/**
 * Record Payment — mobile-app-react-native.md §4. Reached either with
 * `?customerUuid=` from Customer Detail's Record Payment button (that
 * screen already filters out disconnected/suspended customers, routing
 * those to Reconnect & Pay instead — see app/reconnect/[uuid].tsx), or
 * directly from the tab bar with no param, in which case a local
 * name/phone search stands in for customer selection first.
 *
 * Everything here reads/writes the local SQLite cache only — no live API
 * call — matching the offline-first principle that makes this screen work
 * with zero signal, which is the whole point of a field-agent app.
 */
export default function RecordPaymentScreen() {
    const params = useLocalSearchParams<{ customerUuid?: string }>();
    const customerUuidParam = params.customerUuid;
    const router = useRouter();

    const [customer, setCustomer] = useState<LocalCustomer | null>(null);
    const [loadingCustomer, setLoadingCustomer] = useState(Boolean(customerUuidParam));

    // Customer search — only rendered/used when there is no customerUuid param.
    const [search, setSearch] = useState('');
    const [searchResults, setSearchResults] = useState<LocalCustomer[]>([]);

    const [lastPayment, setLastPayment] = useState<LocalPayment | null>(null);
    const [referenceExpanded, setReferenceExpanded] = useState(false);

    const [amount, setAmount] = useState('');
    const [frequency, setFrequency] = useState<PaymentFrequency>('monthly');
    const [months, setMonths] = useState('');
    const [clearArrearsFirst, setClearArrearsFirst] = useState(false);
    const [creditExpanded, setCreditExpanded] = useState(false);
    const [credit, setCredit] = useState('');
    const [receiptUri, setReceiptUri] = useState<string | null>(null);

    const [submitting, setSubmitting] = useState(false);
    const [stage, setStage] = useState<Stage>('form');
    const [confirmedPayment, setConfirmedPayment] = useState<LocalPayment | null>(null);
    const [confirmSynced, setConfirmSynced] = useState(false);

    // --- Resolve the customer from the customerUuid param on every focus
    // (mirrors Customer Detail's own useFocusEffect pattern) ---
    useFocusEffect(
        useCallback(() => {
            if (!customerUuidParam) {
                setLoadingCustomer(false);
                return;
            }

            let cancelled = false;
            setLoadingCustomer(true);

            void getCustomerByUuid(customerUuidParam).then((row) => {
                if (!cancelled) {
                    setCustomer(row);
                    setLoadingCustomer(false);
                }
            });

            return () => {
                cancelled = true;
            };
        }, [customerUuidParam]),
    );

    // --- Local name/phone search when no customerUuid param was given ---
    useEffect(() => {
        if (customerUuidParam) {
            return;
        }

        const term = search.trim().toLowerCase();

        if (!term) {
            setSearchResults([]);
            return;
        }

        let cancelled = false;

        void getAllCustomers().then((all) => {
            if (cancelled) {
                return;
            }

            const needleDigits = term.replace(/\D/g, '');

            setSearchResults(
                all
                    .filter((c) => {
                        const nameMatch = c.name.toLowerCase().includes(term);
                        const phoneDigits = (c.phone ?? '').replace(/\D/g, '');
                        const phoneMatch = needleDigits.length > 0 && phoneDigits.includes(needleDigits);

                        return nameMatch || phoneMatch;
                    })
                    .slice(0, 20),
            );
        });

        return () => {
            cancelled = true;
        };
    }, [search, customerUuidParam]);

    // --- Last payment for the selected customer — feeds the collapsible
    // reference card's "last payment" section (design doc §4) ---
    useEffect(() => {
        if (!customer) {
            setLastPayment(null);
            return;
        }

        let cancelled = false;

        void getLastPaymentForCustomer(customer.uuid).then((row) => {
            if (!cancelled) {
                setLastPayment(row);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [customer?.uuid]);

    function selectCustomer(row: LocalCustomer) {
        setCustomer(row);
        setSearch('');
        setSearchResults([]);
    }

    function clearSelection() {
        setCustomer(null);
        setLastPayment(null);
    }

    /**
     * "Change customer" — surfaced once a customer is selected, either via
     * search (selectCustomer()) or via the `?customerUuid=` deep link from
     * Customer Detail's Record Payment button. Previously there was no way
     * back to the search UI at all once a customer was picked (the tab has
     * no header/back button, and clearSelection() alone doesn't help when
     * `customerUuidParam` is set — the "not found" branch below re-fires
     * for as long as the param exists). resetForm() also drops any
     * half-filled amount/frequency/receipt so switching customers never
     * leaks stale input into the next one's form.
     */
    function handleChangeCustomer() {
        resetForm();

        if (customerUuidParam) {
            router.setParams({ customerUuid: undefined });
        }
    }

    /** Clears every field for the next customer — called once the
     * confirmation view's timer fires and control returns to the Customer
     * List, so a fresh visit to this tab never shows stale form state. */
    function resetForm() {
        setAmount('');
        setFrequency('monthly');
        setMonths('');
        setClearArrearsFirst(false);
        setCredit('');
        setCreditExpanded(false);
        setReceiptUri(null);
        setReferenceExpanded(false);
        setCustomer(null);
        setLastPayment(null);
        setSearch('');
        setSearchResults([]);
    }

    const monthsNumber = months.trim() === '' ? null : Number(months);
    const guideAmount = customer ? calculateGuideAmount(customer.bill, frequency, monthsNumber) : null;

    const validation = useMemo(
        () =>
            validatePaymentForm({
                customerUuid: customer?.uuid ?? null,
                amount,
                frequency,
                months,
            }),
        [customer?.uuid, amount, frequency, months],
    );

    async function handleAddPhoto() {
        const permission = await ImagePicker.requestCameraPermissionsAsync();

        if (!permission.granted) {
            Alert.alert(
                'Camera permission needed',
                'Allow camera access in your device settings to attach a receipt photo.',
            );
            return;
        }

        // Camera-only per mobile-app-react-native.md §4 — no gallery
        // picker, so launchImageLibraryAsync is never called from here.
        const result = await ImagePicker.launchCameraAsync({ allowsEditing: false, quality: 0.6 });

        if (!result.canceled && result.assets[0]) {
            setReceiptUri(result.assets[0].uri);
        }
    }

    async function handleSubmit() {
        if (!validation.valid || !customer || validation.amountValue === null || submitting) {
            return;
        }

        Keyboard.dismiss();
        setSubmitting(true);

        try {
            const row = await insertLocalPayment({
                customer_uuid: customer.uuid,
                amount: validation.amountValue,
                credit: credit.trim() === '' ? 0 : Number(credit),
                frequency,
                months: frequency === 'months' ? validation.monthsValue : null,
                clear_arrears_first:
                    (frequency === 'months' || frequency === 'yearly') && (customer.total_arrears ?? 0) > 0
                        ? clearArrearsFirst
                        : false,
                receipt_local_uri: receiptUri,
            });

            // "Immediately after each local write (if online)" trigger —
            // fire-and-forget, never gates the confirmation UI below, which
            // must show the amber state first regardless of the outcome.
            syncManager.notifyLocalWrite();

            setConfirmedPayment(row);
            setConfirmSynced(false);
            setStage('confirming');
        } finally {
            setSubmitting(false);
        }
    }

    // --- Confirmation stage: poll this device's own row briefly for a
    // near-instant sync, then unconditionally auto-navigate back to the
    // Customer List after a few seconds either way. ---
    useEffect(() => {
        if (stage !== 'confirming' || !confirmedPayment) {
            return;
        }

        let cancelled = false;

        const pollTimer = setInterval(() => {
            void getPaymentByLocalUuid(confirmedPayment.local_uuid).then((row) => {
                if (!cancelled && row?.sync_status === 'synced') {
                    setConfirmSynced(true);
                }
            });
        }, CONFIRM_POLL_MS);

        const navigateTimer = setTimeout(() => {
            clearInterval(pollTimer);
            resetForm();
            setStage('form');
            setConfirmedPayment(null);
            router.replace('/(tabs)/customers');
        }, CONFIRM_DISPLAY_MS);

        return () => {
            cancelled = true;
            clearInterval(pollTimer);
            clearTimeout(navigateTimer);
        };
        // Deliberately keyed only on the identity of the confirmed payment —
        // re-running this on every render would restart the timer/poll.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [stage, confirmedPayment?.local_uuid]);

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    if (stage === 'confirming' && confirmedPayment) {
        const badge = confirmSynced ? CONFIRM_BADGE.synced : CONFIRM_BADGE.queued;

        return (
            <View style={styles.centerFlex}>
                <Card accentColor={confirmSynced ? colors.status.syncedDot : colors.status.offlineDot} style={styles.confirmCard}>
                    <Badge label={badge.label} tone={badge.tone} />
                    <Text style={styles.confirmAmount}>{formatFcfa(confirmedPayment.amount)}</Text>
                    <Text style={styles.confirmSubtitle}>
                        {customer ? `Recorded for ${customer.name}` : 'Payment recorded'}
                    </Text>
                    <Text style={styles.confirmHint}>Returning to the customer list…</Text>
                </Card>
            </View>
        );
    }

    if (loadingCustomer) {
        return (
            <View style={styles.centerFlex}>
                <ActivityIndicator size="large" color={colors.accent.payment} />
            </View>
        );
    }

    if (customer && UNPAYABLE_STATUSES.has(customer.status)) {
        return (
            <View style={styles.flex}>
                <EmptyState
                    title={`${customer.name} is ${customer.status}`}
                    subtitle="A disconnected or suspended customer can't take a new payment here — use Reconnect & Pay from their Customer Detail page first."
                    actionLabel={customerUuidParam ? 'Back to customer' : 'Choose a different customer'}
                    onAction={customerUuidParam ? () => router.back() : clearSelection}
                />
            </View>
        );
    }

    if (!customer && customerUuidParam) {
        // Arrived with a specific customer link (Customer Detail's button)
        // but that uuid isn't in this device's local cache — different
        // zone, or hasn't synced here yet. Falling through to the generic
        // search UI below would be misleading (it would look like this
        // screen just ignored the link), so this gets its own explanation
        // instead, mirroring Customer Detail's own "not found" message.
        return (
            <View style={styles.flex}>
                <EmptyState
                    title="Customer not found in this device's cache"
                    subtitle="It may belong to a different zone, or hasn't synced to this device yet."
                    actionLabel="Go back"
                    onAction={() => router.back()}
                />
            </View>
        );
    }

    if (!customer) {
        return (
            <View style={styles.flex}>
                <View style={styles.searchHeader}>
                    <Text style={styles.searchTitle}>Find a customer</Text>
                    <UiTextInput
                        placeholder="Search by name or phone"
                        value={search}
                        onChangeText={setSearch}
                        autoCapitalize="none"
                        autoCorrect={false}
                        autoFocus
                    />
                </View>
                <ScrollView contentContainerStyle={styles.searchResults} keyboardShouldPersistTaps="handled">
                    {search.trim() === '' ? (
                        <EmptyState
                            title="Search for a customer"
                            subtitle="Type a name or phone number above, or open a customer from the Customers tab and tap Record Payment there."
                        />
                    ) : searchResults.length === 0 ? (
                        <EmptyState title="No matches" subtitle="Try a different name or phone number." />
                    ) : (
                        searchResults.map((row) => (
                            <Card key={row.uuid} onPress={() => selectCustomer(row)} style={styles.searchRow}>
                                <Text style={styles.searchRowName}>{row.name}</Text>
                                <Text style={styles.searchRowMeta}>
                                    {row.phone ? `${row.phone} · ` : ''}
                                    {formatFcfa(row.bill)} bill
                                </Text>
                            </Card>
                        ))
                    )}
                </ScrollView>
            </View>
        );
    }

    const arrears = customer.total_arrears ?? 0;
    const submitDisabled = !validation.valid || submitting;

    return (
        <View style={styles.flex}>
            <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
                {/* 0. Change customer — the only way back to search/pick a
                    different customer once one is selected (see
                    handleChangeCustomer()'s doc comment). */}
                <Pressable
                    accessibilityRole="button"
                    onPress={handleChangeCustomer}
                    style={styles.changeCustomerRow}
                    hitSlop={8}
                >
                    <Text style={styles.changeCustomerLabel}>‹ Change customer</Text>
                </Pressable>

                {/* 1. Customer context header — non-editable, always visible once selected */}
                <Card accentColor={colors.accent.payment}>
                    <Text style={styles.customerName}>{customer.name}</Text>
                    {customer.location ? <Text style={styles.customerLocation}>{customer.location}</Text> : null}
                    <View style={styles.contextRow}>
                        <View>
                            <Text style={styles.contextLabel}>Bill</Text>
                            <Text style={styles.contextValue}>{formatFcfa(customer.bill)}</Text>
                        </View>
                        <View>
                            <Text style={styles.contextLabel}>Arrears</Text>
                            <Text style={[styles.contextValue, arrears > 0 && styles.contextValueDanger]}>
                                {formatFcfa(arrears)}
                            </Text>
                        </View>
                    </View>
                </Card>

                {/* 2. Collapsible reference card — collapsed by default */}
                <Card>
                    <Pressable
                        accessibilityRole="button"
                        accessibilityState={{ expanded: referenceExpanded }}
                        onPress={() => setReferenceExpanded((value) => !value)}
                        style={styles.referenceHeader}
                        hitSlop={8}
                    >
                        <View style={styles.referenceHeaderText}>
                            <Text style={styles.referenceTitle}>Payment reference</Text>
                            <Text style={styles.referenceSubtitle}>
                                Bill {formatFcfa(customer.bill)}
                                {arrears > 0 ? ` · Arrears ${formatFcfa(arrears)}` : ''}
                            </Text>
                        </View>
                        <Text style={styles.referenceToggle}>{referenceExpanded ? 'Hide' : 'Show'}</Text>
                    </Pressable>

                    {referenceExpanded ? (
                        <View style={styles.referenceBody}>
                            <View style={styles.referenceSection}>
                                <Text style={styles.referenceSectionTitle}>Last payment</Text>
                                {lastPayment ? (
                                    <>
                                        <Text style={styles.referenceLine}>
                                            {formatFcfa(lastPayment.amount)} · {frequencyLabel(lastPayment.frequency)}
                                        </Text>
                                        <Text style={styles.referenceLineMuted}>
                                            {new Date(lastPayment.created_at).toLocaleDateString()} ·{' '}
                                            {verificationLabel(lastPayment.verification_status)}
                                        </Text>
                                    </>
                                ) : (
                                    <Text style={styles.referenceLineMuted}>
                                        No payment recorded from this device yet.
                                    </Text>
                                )}
                            </View>

                            <View style={styles.referenceSection}>
                                <Text style={styles.referenceSectionTitle}>Reference only — not filled in for you</Text>
                                <Text style={styles.referenceLineMuted}>
                                    A live guide for the frequency selected below. It never fills in the amount field —
                                    type the amount the customer actually paid.
                                </Text>
                                {frequency === 'months' && guideAmount === null ? (
                                    <Text style={styles.referenceLine}>
                                        Enter a number of months below to see a suggested total.
                                    </Text>
                                ) : (
                                    <Text style={styles.referenceLine}>
                                        {frequencyLabel(frequency)} ({formatFcfa(customer.bill)}
                                        {frequency === 'monthly' ? '' : frequency === 'yearly' ? ' × 12' : ` × ${months || 'N'}`}
                                        ): <Text style={styles.referenceAmount}>{guideAmount !== null ? formatFcfa(guideAmount) : '—'}</Text>
                                    </Text>
                                )}
                            </View>
                        </View>
                    ) : null}
                </Card>

                {/* 3. Amount field — the single dominant visual element */}
                <View style={styles.amountSection}>
                    <Pressable
                        accessibilityRole="button"
                        onPress={() => setAmount(String(Math.round(customer.bill)))}
                        style={styles.billChip}
                        hitSlop={8}
                    >
                        <Text style={styles.billChipLabel}>Use bill amount: {formatFcfa(customer.bill)}</Text>
                    </Pressable>
                    <UiTextInput
                        label="Amount (FCFA)"
                        value={amount}
                        onChangeText={setAmount}
                        keyboardType="number-pad"
                        placeholder="0"
                        autoFocus
                        style={styles.amountInput}
                        error={validation.errors.amount}
                    />
                </View>

                {/* 4. Frequency — segmented control */}
                <View style={styles.fieldGroup}>
                    <Text style={styles.fieldLabel}>Frequency</Text>
                    <View style={styles.segmented}>
                        {FREQUENCIES.map((option) => {
                            const active = frequency === option.value;

                            return (
                                <Pressable
                                    key={option.value}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected: active }}
                                    onPress={() => setFrequency(option.value)}
                                    style={[styles.segmentedOption, active && styles.segmentedOptionActive]}
                                >
                                    <Text style={[styles.segmentedLabel, active && styles.segmentedLabelActive]}>
                                        {option.label}
                                    </Text>
                                </Pressable>
                            );
                        })}
                    </View>
                </View>

                {/* 5. Months — conditional on Multi-month */}
                {frequency === 'months' ? (
                    <UiTextInput
                        label="Number of months"
                        value={months}
                        onChangeText={setMonths}
                        keyboardType="number-pad"
                        placeholder="e.g. 3"
                        error={validation.errors.months}
                    />
                ) : null}

                {/* 5b. Clear-arrears-first — only for a prepayment against a
                    customer who owes (draw-down Q1). */}
                {(frequency === 'months' || frequency === 'yearly') && arrears > 0 ? (
                    <Pressable
                        accessibilityRole="checkbox"
                        accessibilityState={{ checked: clearArrearsFirst }}
                        onPress={() => setClearArrearsFirst((value) => !value)}
                        style={styles.checkboxRow}
                        hitSlop={6}
                    >
                        <View style={[styles.checkbox, clearArrearsFirst && styles.checkboxChecked]}>
                            {clearArrearsFirst ? <Text style={styles.checkboxTick}>{'✓'}</Text> : null}
                        </View>
                        <Text style={styles.checkboxLabel}>
                            Clear the {formatFcfa(arrears)} arrears first, then buy prepaid months with the rest
                        </Text>
                    </Pressable>
                ) : null}

                {/* 6. Credit — optional, collapsed under a disclosure */}
                <Pressable
                    accessibilityRole="button"
                    accessibilityState={{ expanded: creditExpanded }}
                    onPress={() => setCreditExpanded((value) => !value)}
                    style={styles.disclosure}
                    hitSlop={8}
                >
                    <Text style={styles.disclosureLabel}>
                        {creditExpanded ? 'Hide credit field' : '+ Add credit (optional)'}
                    </Text>
                </Pressable>
                {creditExpanded ? (
                    <UiTextInput
                        label="Credit (optional)"
                        value={credit}
                        onChangeText={setCredit}
                        keyboardType="number-pad"
                        placeholder="0"
                    />
                ) : null}

                {/* 7. Receipt photo — optional, camera-only */}
                <View style={styles.fieldGroup}>
                    <Text style={styles.fieldLabel}>Receipt photo (optional)</Text>
                    {receiptUri ? (
                        <View style={styles.receiptRow}>
                            <Image source={{ uri: receiptUri }} style={styles.receiptThumb} />
                            <Button title="Retake" variant="secondary" fullWidth={false} onPress={handleAddPhoto} />
                        </View>
                    ) : (
                        <Button title="Add photo" variant="secondary" onPress={handleAddPhoto} />
                    )}
                </View>
            </ScrollView>

            {/* 8. Submit — full-width, thumb zone, always visible */}
            <View style={styles.footer}>
                <Button
                    title={submitting ? 'Saving…' : 'Record Payment'}
                    size="large"
                    loading={submitting}
                    disabled={submitDisabled}
                    onPress={handleSubmit}
                />
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },

    checkboxRow: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm, paddingVertical: spacing.xs },
    checkbox: {
        width: 22,
        height: 22,
        borderRadius: 6,
        borderWidth: 2,
        borderColor: colors.border,
        alignItems: 'center',
        justifyContent: 'center',
        marginTop: 1,
    },
    checkboxChecked: { backgroundColor: colors.accent.payment, borderColor: colors.accent.payment },
    checkboxTick: { color: colors.background, fontSize: fontSize.sm, fontWeight: '900', lineHeight: fontSize.sm + 2 },
    checkboxLabel: { flex: 1, fontSize: fontSize.sm, color: colors.textPrimary, lineHeight: fontSize.sm + 6 },
    centerFlex: { flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center', padding: spacing.lg },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },

    changeCustomerRow: { alignSelf: 'flex-start', paddingVertical: spacing.xs },
    changeCustomerLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.payment },

    customerName: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    customerLocation: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    contextRow: { flexDirection: 'row', gap: spacing.xl, marginTop: spacing.md },
    contextLabel: { fontSize: fontSize.xs, fontWeight: '600', color: colors.textSecondary },
    contextValue: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary, marginTop: 2 },
    contextValueDanger: { color: colors.danger },

    referenceHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    referenceHeaderText: { flexShrink: 1 },
    referenceTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    referenceSubtitle: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    referenceToggle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.payment },
    referenceBody: { marginTop: spacing.md, gap: spacing.md, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: spacing.md },
    referenceSection: { gap: 2 },
    referenceSectionTitle: {
        fontSize: fontSize.xs,
        fontWeight: '700',
        color: colors.textSecondary,
        textTransform: 'uppercase',
        letterSpacing: 0.3,
    },
    referenceLine: { fontSize: fontSize.sm, color: colors.textPrimary, marginTop: 2 },
    referenceLineMuted: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    referenceAmount: { fontWeight: '800', color: colors.textPrimary },

    amountSection: { gap: spacing.sm },
    // billChip, referenceHeader, and disclosure below are all visually
    // compact by design (pill chip / disclosure link, not full buttons) —
    // their padding alone falls under the 48dp touch-target floor
    // (mobile-app-react-native.md §6). Rather than growing them visually
    // (which would look oversized for what they are), each Pressable gets
    // hitSlop={8} to extend the actual touchable area without changing
    // appearance — the same technique already used on this screen's
    // "‹ Change customer" link above.
    billChip: {
        alignSelf: 'flex-start',
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        borderRadius: radius.pill,
        backgroundColor: colors.status.syncedBg,
        borderWidth: 1,
        borderColor: colors.status.syncedDot,
    },
    billChipLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.status.syncedFg },
    amountInput: {
        fontSize: fontSize.display,
        fontWeight: '800',
        textAlign: 'center',
        height: 76,
        color: colors.textPrimary,
    },

    fieldGroup: { gap: spacing.sm },
    fieldLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    segmented: { flexDirection: 'row', backgroundColor: colors.surfaceMuted, borderRadius: radius.md, padding: spacing.xs, gap: spacing.xs },
    segmentedOption: {
        flex: 1,
        minHeight: touchTarget.floor,
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: radius.sm,
    },
    segmentedOptionActive: { backgroundColor: colors.surface, shadowColor: '#000', shadowOpacity: 0.08, shadowRadius: 4, elevation: 1 },
    segmentedLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    segmentedLabelActive: { color: colors.textPrimary },

    disclosure: { paddingVertical: spacing.sm },
    disclosureLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.payment },

    receiptRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    receiptThumb: { width: 72, height: 72, borderRadius: radius.md, backgroundColor: colors.surfaceMuted },

    footer: {
        padding: spacing.lg,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        backgroundColor: colors.background,
    },

    searchHeader: { padding: spacing.lg, gap: spacing.md },
    searchTitle: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    searchResults: { padding: spacing.lg, paddingTop: 0, gap: spacing.sm, flexGrow: 1 },
    searchRow: { gap: 2 },
    searchRowName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    searchRowMeta: { fontSize: fontSize.sm, color: colors.textSecondary },

    confirmCard: { alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.xl, width: '100%', maxWidth: 360 },
    confirmAmount: { fontSize: fontSize.display, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.sm },
    confirmSubtitle: { fontSize: fontSize.md, color: colors.textSecondary },
    confirmHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.md },
});
