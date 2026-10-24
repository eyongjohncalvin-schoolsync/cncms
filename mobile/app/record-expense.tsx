import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import {
    Animated,
    Image,
    KeyboardAvoidingView,
    Platform,
    Pressable,
    ScrollView,
    StyleSheet,
    Text,
    Vibration,
    View,
} from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { TextInput } from '../src/components/ui/TextInput';
import { NumericKeypad, keypadTick } from '../src/components/ui/NumericKeypad';
import { SupplierPicker } from '../src/components/expenses/SupplierPicker';
import { captureReceipt, type CapturedReceipt } from '../src/components/expenses/captureReceipt';
import { getExpenseCategories } from '../src/db/categories';
import {
    getCategoryUsage,
    getExpenditureByLocalUuid,
    getLastExpenditure,
    insertLocalExpenditure,
} from '../src/db/expenditures';
import { getSuppliers } from '../src/db/suppliers';
import { getCachedExpenseLimits, getCachedServices, type CachedService } from '../src/db/expenseReference';
import { syncManager } from '../src/sync/SyncManager';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { glyphForCategoryIcon } from '../src/utils/categoryIcons';
import {
    approvalHintFor,
    EXPENSE_PAYMENT_METHODS,
    isBackdatedBeyondLimit,
    isMomoMethod,
    isReceiptRequired,
    isSupplierRequired,
    parseExpenseLimits,
    rankCategoriesByUsage,
    type ApprovalPath,
    type ExpenseLimits,
} from '../src/utils/expenseControls';
import { formatGroupedAmount, keypadValueToNumber, numberToKeypadValue } from '../src/utils/keypad';
import { formatFcfa, todayDateOnly, yesterdayDateOnly } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { LocalExpenditure, LocalExpenseCategory, LocalSupplier, PaymentMethod, SyncStatus } from '../src/types/db';

type Step = 'amount' | 'details' | 'saved';
type DateChoice = 'today' | 'yesterday';

interface SavedSummary {
    localUuid: string;
    amount: number;
    categoryName: string;
    approvalPath: ApprovalPath | null;
    receiptMissing: boolean;
}

interface FormErrors {
    category?: string;
    paymentRef?: string;
}

const HINT_TONE: Record<ApprovalPath, BadgeTone> = {
    auto: 'verified',
    manager: 'pending',
    manager_owner: 'pending',
};

const SYNC_BADGE: Record<SyncStatus, { label: string; tone: BadgeTone }> = {
    queued: { label: 'Saved · will sync', tone: 'offline' },
    syncing: { label: 'Syncing…', tone: 'syncing' },
    synced: { label: 'Synced', tone: 'synced' },
    failed: { label: 'Saved · will retry', tone: 'offline' },
};

function successBuzz(): void {
    if (Platform.OS === 'android') {
        Vibration.vibrate([0, 35, 70, 35]);
    }
}

/**
 * Record Expense — rebuilt for the expense controls (2026-10) as the
 * fastest screen in the app. Two steps:
 *
 *   1. Amount first, on a big custom keypad (NumericKeypad) with the
 *      amount in words underneath, plus a "Repeat last" chip that refills
 *      everything from the previous expense in one tap.
 *   2. Details: category tiles (most-used first), Paid by (Cash / MTN /
 *      Orange / Bank, MoMo ref required for MoMo), supplier (searchable,
 *      offline cache), service (default Shared), Today / Yesterday, an
 *      optional note, and the receipt photo (camera or gallery).
 *
 * Live hints from the cached company limits tell the worker what will
 * happen ("Approved instantly", "A manager will approve"…), and a missing
 * receipt above the limit is a nudge, never a blocker — the server holds
 * the expense as "waiting for receipt". Saving writes to the local outbox
 * (src/db/expenditures.ts) and nudges SyncManager; the confirmation starts
 * amber "Saved · will sync" and flips green if the sync lands while the
 * worker is still looking.
 */
export default function RecordExpenseScreen() {
    const router = useRouter();
    const syncState = useSyncExternalStore(subscribeSyncState, getSyncState);
    const isOnline = syncState.isOnline;

    const [step, setStep] = useState<Step>('amount');
    const [amountDigits, setAmountDigits] = useState('');
    const [categories, setCategories] = useState<LocalExpenseCategory[]>([]);
    const [categoryUuid, setCategoryUuid] = useState<string | null>(null);
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [paymentRef, setPaymentRef] = useState('');
    const [suppliers, setSuppliers] = useState<LocalSupplier[]>([]);
    const [supplierUuid, setSupplierUuid] = useState<string | null>(null);
    const [supplierPickerOpen, setSupplierPickerOpen] = useState(false);
    const [services, setServices] = useState<CachedService[]>([]);
    const [serviceUuid, setServiceUuid] = useState<string | null>(null);
    const [dateChoice, setDateChoice] = useState<DateChoice>('today');
    const [description, setDescription] = useState('');
    const [notes, setNotes] = useState('');
    const [referenceNo, setReferenceNo] = useState('');
    const [showMore, setShowMore] = useState(false);
    const [receipt, setReceipt] = useState<CapturedReceipt | null>(null);
    const [limits, setLimits] = useState<ExpenseLimits | null>(null);
    const [lastExpense, setLastExpense] = useState<LocalExpenditure | null>(null);
    const [errors, setErrors] = useState<FormErrors>({});
    const [submitting, setSubmitting] = useState(false);
    const [saved, setSaved] = useState<SavedSummary | null>(null);
    const [savedSyncStatus, setSavedSyncStatus] = useState<SyncStatus>('queued');

    const checkScale = useRef(new Animated.Value(0)).current;

    const amount = keypadValueToNumber(amountDigits);
    const spentAt = dateChoice === 'today' ? todayDateOnly() : yesterdayDateOnly();

    const loadReferenceData = useCallback(() => {
        void Promise.all([getSuppliers(), getCachedServices(), getCachedExpenseLimits()]).then(
            ([supplierRows, serviceRows, limitsApi]) => {
                setSuppliers(supplierRows);
                setServices(serviceRows);
                setLimits(parseExpenseLimits(limitsApi));
            },
        );
    }, []);

    useFocusEffect(
        useCallback(() => {
            void Promise.all([getExpenseCategories(), getCategoryUsage(), getLastExpenditure()]).then(
                ([categoryRows, usage, last]) => {
                    setCategories(rankCategoriesByUsage(categoryRows, usage));
                    setLastExpense(last);
                },
            );
            loadReferenceData();
            void syncManager.refreshExpenseReferenceData();
        }, [loadReferenceData]),
    );

    // Re-read the cached limits/suppliers/services whenever a sync tick
    // refreshes them, and flip the confirmation badge when the save lands.
    useEffect(
        () =>
            subscribeSyncState(() => {
                loadReferenceData();

                if (saved) {
                    void getExpenditureByLocalUuid(saved.localUuid).then((row) => {
                        if (row) {
                            setSavedSyncStatus(row.sync_status);
                        }
                    });
                }
            }),
        [loadReferenceData, saved],
    );

    const categoryMap = useMemo(() => new Map(categories.map((c) => [c.uuid, c])), [categories]);
    const supplierMap = useMemo(() => new Map(suppliers.map((s) => [s.uuid, s])), [suppliers]);
    const selectedSupplier = supplierUuid ? supplierMap.get(supplierUuid) : undefined;

    const approvalHint = approvalHintFor(amount, limits);
    const receiptRequired = isReceiptRequired(amount, limits);
    const supplierRequired = isSupplierRequired(amount, limits);
    const needsMomoRef = isMomoMethod(method);
    const dateNeedsManager = isBackdatedBeyondLimit(spentAt, todayDateOnly(), limits);

    function resetForm() {
        setStep('amount');
        setAmountDigits('');
        setCategoryUuid(null);
        setMethod('cash');
        setPaymentRef('');
        setSupplierUuid(null);
        setServiceUuid(null);
        setDateChoice('today');
        setDescription('');
        setNotes('');
        setReferenceNo('');
        setShowMore(false);
        setReceipt(null);
        setErrors({});
        setSaved(null);
        setSavedSyncStatus('queued');
        checkScale.setValue(0);
        void Promise.all([getExpenseCategories(), getCategoryUsage(), getLastExpenditure()]).then(
            ([categoryRows, usage, last]) => {
                setCategories(rankCategoriesByUsage(categoryRows, usage));
                setLastExpense(last);
            },
        );
    }

    function repeatLast() {
        if (!lastExpense) {
            return;
        }

        keypadTick();
        setAmountDigits(numberToKeypadValue(lastExpense.amount));
        setCategoryUuid(categoryMap.has(lastExpense.category_uuid) ? lastExpense.category_uuid : null);
        setMethod(lastExpense.payment_method ?? 'cash');
        // A MoMo transaction ID is single-use — never copied over.
        setPaymentRef('');
        setSupplierUuid(lastExpense.supplier_uuid && supplierMap.has(lastExpense.supplier_uuid) ? lastExpense.supplier_uuid : null);
        setServiceUuid(lastExpense.service_uuid);
        setDescription(lastExpense.description ?? '');
        setErrors({});
        setStep('details');
    }

    async function handleReceipt(source: 'camera' | 'gallery') {
        const captured = await captureReceipt(source);

        if (captured) {
            setReceipt(captured);
        }
    }

    async function handleSave() {
        const nextErrors: FormErrors = {};

        if (!categoryUuid) {
            nextErrors.category = 'Pick what the money was for.';
        }

        if (needsMomoRef && paymentRef.trim() === '') {
            nextErrors.paymentRef = 'Enter the transaction ID from the MoMo SMS.';
        }

        if (Object.keys(nextErrors).length > 0 || !categoryUuid || amount <= 0) {
            setErrors(nextErrors);
            return;
        }

        setErrors({});
        setSubmitting(true);

        try {
            const row = await insertLocalExpenditure({
                category_uuid: categoryUuid,
                amount,
                description,
                spent_at: spentAt,
                notes,
                payment_method: method,
                payment_ref: needsMomoRef ? paymentRef : null,
                supplier_uuid: supplierUuid,
                service_uuid: serviceUuid,
                reference_no: referenceNo,
                receipt_local_uri: receipt?.uri ?? null,
                receipt_source: receipt?.source ?? null,
                receipt_captured_at: receipt?.capturedAt ?? null,
            });

            setSaved({
                localUuid: row.local_uuid,
                amount,
                categoryName: categoryMap.get(categoryUuid)?.name ?? 'Expense',
                approvalPath: approvalHint?.path ?? null,
                receiptMissing: receiptRequired && !receipt,
            });
            setSavedSyncStatus('queued');
            setStep('saved');
            successBuzz();
            Animated.spring(checkScale, { toValue: 1, friction: 5, tension: 120, useNativeDriver: true }).start();

            // Best-effort immediate sync — never awaited, so the calm
            // offline confirmation never waits on the network.
            syncManager.notifyLocalWrite();
        } finally {
            setSubmitting(false);
        }
    }

    // ------------------------------------------------------------------
    // Step 3 — confirmation
    // ------------------------------------------------------------------
    if (step === 'saved' && saved) {
        const badge = SYNC_BADGE[savedSyncStatus];

        return (
            <View style={styles.confirmFlex}>
                <Animated.View style={[styles.checkCircle, { transform: [{ scale: checkScale }] }]}>
                    <Text style={styles.checkGlyph}>✓</Text>
                </Animated.View>
                <Text style={styles.confirmTitle}>Expense saved</Text>
                <Text style={styles.confirmAmount}>{formatFcfa(saved.amount)}</Text>
                <Text style={styles.confirmBody}>{saved.categoryName}</Text>
                <Badge label={badge.label} tone={badge.tone} />
                <Text style={styles.confirmHint}>
                    {savedSyncStatus === 'synced'
                        ? 'It reached the office.'
                        : "Saved on this phone. It syncs by itself when you're online — nothing else to do."}
                </Text>
                {saved.approvalPath ? (
                    <Text style={styles.confirmHint}>
                        {saved.approvalPath === 'auto'
                            ? saved.receiptMissing
                                ? 'Approved once the receipt photo is added.'
                                : 'Approved instantly.'
                            : saved.approvalPath === 'manager'
                              ? 'A manager will approve it.'
                              : 'A manager and the owner will approve it.'}
                    </Text>
                ) : null}
                {saved.receiptMissing ? (
                    <Card accentColor={colors.status.offlineDot} style={styles.confirmNudge}>
                        <Text style={styles.nudgeTitle}>Receipt photo still needed</Text>
                        <Text style={styles.nudgeBody}>Add it any time from Expenses — it waits for the photo.</Text>
                    </Card>
                ) : null}
                <View style={styles.confirmActions}>
                    <Button title="Record another" variant="secondary" size="large" onPress={resetForm} />
                    <Button title="Done" size="large" onPress={() => router.back()} />
                </View>
            </View>
        );
    }

    // ------------------------------------------------------------------
    // Step 1 — amount
    // ------------------------------------------------------------------
    if (step === 'amount') {
        const lastCategory = lastExpense ? categoryMap.get(lastExpense.category_uuid) : undefined;

        return (
            <ScrollView style={styles.flex} contentContainerStyle={styles.amountContent} bounces={false}>
                <View style={styles.amountTop}>
                    {lastExpense ? (
                        <Pressable
                            accessibilityRole="button"
                            accessibilityLabel="Repeat last expense"
                            onPress={repeatLast}
                            style={({ pressed }) => [styles.repeatChip, pressed && styles.pressed]}
                        >
                            <Text style={styles.repeatGlyph}>↺</Text>
                            <Text style={styles.repeatText} numberOfLines={1}>
                                Repeat last · {lastCategory?.name ?? 'Expense'} · {formatGroupedAmount(lastExpense.amount)}
                            </Text>
                        </Pressable>
                    ) : null}

                    <NumericKeypad value={amountDigits} onChange={setAmountDigits} />

                    <View style={styles.hintSlot}>
                        {approvalHint ? <Badge label={approvalHint.label} tone={HINT_TONE[approvalHint.path]} /> : null}
                        {receiptRequired ? <Badge label="Receipt photo required" tone="warning" /> : null}
                    </View>
                </View>

                <Button title="Next" size="large" disabled={amount <= 0} onPress={() => setStep('details')} />
            </ScrollView>
        );
    }

    // ------------------------------------------------------------------
    // Step 2 — details
    // ------------------------------------------------------------------
    return (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={80}>
            <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
                <Card onPress={() => setStep('amount')} accentColor={colors.accent.expense}>
                    <View style={styles.amountSummaryRow}>
                        <View style={styles.flexShrink}>
                            <Text style={styles.amountSummary}>{formatGroupedAmount(amountDigits)} FCFA</Text>
                            <Text style={styles.amountEdit}>Tap to change the amount</Text>
                        </View>
                        {approvalHint ? <Badge label={approvalHint.label} tone={HINT_TONE[approvalHint.path]} /> : null}
                    </View>
                </Card>

                {/* What for — most-used categories first */}
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>What for?</Text>
                    {categories.length === 0 ? (
                        <Text style={styles.mutedText}>
                            {isOnline ? 'Loading categories…' : 'Categories load the first time you are online.'}
                        </Text>
                    ) : (
                        <View style={styles.tileGrid}>
                            {categories.map((category) => {
                                const selected = category.uuid === categoryUuid;

                                return (
                                    <Pressable
                                        key={category.uuid}
                                        accessibilityRole="button"
                                        accessibilityState={{ selected }}
                                        accessibilityLabel={category.name}
                                        onPress={() => {
                                            keypadTick();
                                            setCategoryUuid(category.uuid);
                                            setErrors((prev) => ({ ...prev, category: undefined }));
                                        }}
                                        style={({ pressed }) => [styles.tile, selected && styles.tileSelected, pressed && styles.pressed]}
                                    >
                                        <Text style={styles.tileGlyph}>{glyphForCategoryIcon(category.icon, category.name)}</Text>
                                        <Text style={[styles.tileName, selected && styles.tileNameSelected]} numberOfLines={2}>
                                            {category.name}
                                        </Text>
                                    </Pressable>
                                );
                            })}
                        </View>
                    )}
                    {errors.category ? <Text style={styles.errorText}>{errors.category}</Text> : null}
                </View>

                {/* Paid by */}
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>Paid by</Text>
                    <View style={styles.segmented}>
                        {EXPENSE_PAYMENT_METHODS.map((option) => {
                            const active = method === option.value;

                            return (
                                <Pressable
                                    key={option.value}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected: active }}
                                    onPress={() => {
                                        setMethod(option.value);
                                        setErrors((prev) => ({ ...prev, paymentRef: undefined }));
                                    }}
                                    style={[styles.segmentedOption, active && styles.segmentedOptionActive]}
                                >
                                    <Text style={[styles.segmentedLabel, active && styles.segmentedLabelActive]}>{option.label}</Text>
                                </Pressable>
                            );
                        })}
                    </View>
                    {needsMomoRef ? (
                        <TextInput
                            label="MoMo transaction ID"
                            value={paymentRef}
                            onChangeText={(text) => {
                                setPaymentRef(text);
                                setErrors((prev) => ({ ...prev, paymentRef: undefined }));
                            }}
                            autoCapitalize="characters"
                            autoCorrect={false}
                            placeholder="From the MoMo SMS"
                            error={errors.paymentRef}
                        />
                    ) : null}
                </View>

                {/* Supplier */}
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>Paid to</Text>
                    <Pressable
                        accessibilityRole="button"
                        onPress={() => setSupplierPickerOpen(true)}
                        style={({ pressed }) => [styles.pickerRow, pressed && styles.pressed]}
                    >
                        <View style={styles.flexShrink}>
                            <Text style={styles.pickerValue} numberOfLines={1}>
                                {selectedSupplier ? selectedSupplier.name : 'No supplier'}
                            </Text>
                            {selectedSupplier?.status === 'pending' ? (
                                <Text style={styles.pickerMeta}>Awaiting approval</Text>
                            ) : (
                                <Text style={styles.pickerMeta}>Tap to choose or add a supplier</Text>
                            )}
                        </View>
                        <Text style={styles.chevron}>›</Text>
                    </Pressable>
                    {supplierRequired && !supplierUuid ? (
                        <Text style={styles.nudgeText}>Name the supplier for amounts this size — the office will ask.</Text>
                    ) : null}
                </View>

                {/* Service */}
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>For which service?</Text>
                    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chipRow}>
                        {[{ uuid: null as string | null, name: 'Shared' }, ...services].map((service) => {
                            const active = service.uuid === serviceUuid;

                            return (
                                <Pressable
                                    key={service.uuid ?? 'shared'}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected: active }}
                                    onPress={() => setServiceUuid(service.uuid)}
                                    style={[styles.chip, active && styles.chipActive]}
                                >
                                    <Text style={[styles.chipText, active && styles.chipTextActive]}>{service.name}</Text>
                                </Pressable>
                            );
                        })}
                    </ScrollView>
                </View>

                {/* When */}
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>When?</Text>
                    <View style={styles.dateRow}>
                        {(['today', 'yesterday'] as DateChoice[]).map((choice) => {
                            const active = dateChoice === choice;

                            return (
                                <Pressable
                                    key={choice}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected: active }}
                                    onPress={() => setDateChoice(choice)}
                                    style={[styles.dateChip, active && styles.chipActive]}
                                >
                                    <Text style={[styles.chipText, active && styles.chipTextActive]}>
                                        {choice === 'today' ? 'Today' : 'Yesterday'}
                                    </Text>
                                </Pressable>
                            );
                        })}
                    </View>
                    <Text style={styles.mutedText}>
                        {dateNeedsManager
                            ? 'A manager will check an expense dated yesterday.'
                            : 'Older than yesterday? Ask a manager to record it.'}
                    </Text>
                </View>

                {/* Receipt */}
                <View style={styles.section}>
                    <View style={styles.sectionHeaderRow}>
                        <Text style={styles.sectionLabel}>Receipt photo</Text>
                        {receiptRequired ? (
                            <Badge label={receipt ? 'Added' : 'Required'} tone={receipt ? 'verified' : 'warning'} />
                        ) : (
                            <Text style={styles.mutedText}>Optional</Text>
                        )}
                    </View>
                    {receipt ? (
                        <Card>
                            <Image source={{ uri: receipt.uri }} style={styles.photoPreview} />
                            <View style={styles.photoActions}>
                                <Button
                                    title="Retake"
                                    variant="ghost"
                                    fullWidth={false}
                                    onPress={() => void handleReceipt(receipt.source)}
                                />
                                <Button title="Remove" variant="ghost" fullWidth={false} onPress={() => setReceipt(null)} />
                            </View>
                        </Card>
                    ) : (
                        <>
                            {receiptRequired ? (
                                <Card accentColor={colors.status.offlineDot}>
                                    <Text style={styles.nudgeTitle}>Receipt photo required</Text>
                                    <Text style={styles.nudgeBody}>
                                        You can still save — it waits for the photo before approval. Add it now to save a trip.
                                    </Text>
                                </Card>
                            ) : null}
                            <View style={styles.receiptButtons}>
                                <Button
                                    title="📷  Take photo"
                                    variant="secondary"
                                    onPress={() => void handleReceipt('camera')}
                                    style={styles.flexOne}
                                    fullWidth={false}
                                />
                                <Button
                                    title="From gallery"
                                    variant="secondary"
                                    onPress={() => void handleReceipt('gallery')}
                                    style={styles.flexOne}
                                    fullWidth={false}
                                />
                            </View>
                        </>
                    )}
                </View>

                {/* Note */}
                <TextInput
                    label="Note (optional)"
                    placeholder="e.g. Fuel for zone rounds"
                    value={description}
                    onChangeText={setDescription}
                />

                {showMore ? (
                    <>
                        <TextInput
                            label="Invoice / receipt number (optional)"
                            value={referenceNo}
                            onChangeText={setReferenceNo}
                            autoCapitalize="characters"
                            autoCorrect={false}
                        />
                        <TextInput
                            label="More details (optional)"
                            value={notes}
                            onChangeText={setNotes}
                            multiline
                            style={styles.notesInput}
                        />
                    </>
                ) : (
                    <Pressable accessibilityRole="button" onPress={() => setShowMore(true)} style={styles.disclosure}>
                        <Text style={styles.disclosureText}>+ Invoice number or more details</Text>
                    </Pressable>
                )}
            </ScrollView>

            <View style={styles.footer}>
                <Button
                    title={`Save expense · ${formatGroupedAmount(amountDigits)} FCFA`}
                    size="large"
                    loading={submitting}
                    onPress={() => void handleSave()}
                />
            </View>

            <SupplierPicker
                visible={supplierPickerOpen}
                suppliers={suppliers}
                selectedUuid={supplierUuid}
                isOnline={isOnline}
                onClose={() => setSupplierPickerOpen(false)}
                onSelect={(uuid) => {
                    setSupplierUuid(uuid);
                    setSupplierPickerOpen(false);
                }}
                onCreated={(uuid) => {
                    void getSuppliers().then((rows) => {
                        setSuppliers(rows);
                        setSupplierUuid(uuid);
                        setSupplierPickerOpen(false);
                    });
                }}
            />
        </KeyboardAvoidingView>
    );
}

// White text on accent.expense (#6B21A8) is ~8.7:1 (colors.ts) — AAA.
const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    flexOne: { flex: 1 },
    flexShrink: { flexShrink: 1 },
    pressed: { opacity: 0.85 },
    amountContent: {
        flexGrow: 1,
        justifyContent: 'space-between',
        padding: spacing.lg,
        paddingBottom: spacing.xl,
        gap: spacing.lg,
    },
    amountTop: { gap: spacing.md },
    repeatChip: {
        alignSelf: 'center',
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
        minHeight: touchTarget.floor,
        maxWidth: '100%',
        paddingHorizontal: spacing.lg,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.accent.expense,
        backgroundColor: '#F5EBFC',
    },
    repeatGlyph: { fontSize: fontSize.lg, fontWeight: '800', color: colors.accent.expense },
    repeatText: { flexShrink: 1, fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.expense },
    hintSlot: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: spacing.sm, minHeight: 28 },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    amountSummaryRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    amountSummary: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.accent.expense },
    amountEdit: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    section: { gap: spacing.sm },
    sectionHeaderRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    sectionLabel: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    mutedText: { fontSize: fontSize.sm, color: colors.textSecondary },
    errorText: { fontSize: fontSize.sm, color: colors.danger, fontWeight: '600' },
    nudgeText: { fontSize: fontSize.sm, color: colors.status.offlineFg, fontWeight: '600' },
    tileGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    tile: {
        width: '31.5%',
        minHeight: 84,
        alignItems: 'center',
        justifyContent: 'center',
        gap: spacing.xs,
        padding: spacing.sm,
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    tileSelected: { borderWidth: 2, borderColor: colors.accent.expense, backgroundColor: '#F5EBFC' },
    tileGlyph: { fontSize: 26 },
    tileName: { fontSize: fontSize.xs, fontWeight: '700', color: colors.textPrimary, textAlign: 'center' },
    tileNameSelected: { color: colors.accent.expense },
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
    segmentedLabelActive: { color: colors.textPrimary, fontWeight: '800' },
    pickerRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        minHeight: touchTarget.primary,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.md,
        backgroundColor: colors.surface,
    },
    pickerValue: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    pickerMeta: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    chevron: { fontSize: fontSize.xl, color: colors.textSecondary, marginLeft: spacing.sm },
    chipRow: { gap: spacing.sm },
    chip: {
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        paddingHorizontal: spacing.lg,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: { borderColor: colors.accent.expense, backgroundColor: colors.accent.expense },
    chipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipTextActive: { color: colors.textInverse },
    dateRow: { flexDirection: 'row', gap: spacing.sm },
    dateChip: {
        flex: 1,
        minHeight: touchTarget.floor,
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    nudgeTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.status.offlineFg },
    nudgeBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    receiptButtons: { flexDirection: 'row', gap: spacing.sm },
    photoPreview: { width: '100%', height: 200, borderRadius: radius.md, backgroundColor: colors.surfaceMuted },
    photoActions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
    notesInput: { minHeight: 72, textAlignVertical: 'top', paddingTop: spacing.md },
    disclosure: { minHeight: touchTarget.floor, justifyContent: 'center' },
    disclosureText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.expense },
    footer: {
        padding: spacing.lg,
        paddingTop: spacing.sm,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        backgroundColor: colors.background,
    },
    confirmFlex: {
        flex: 1,
        backgroundColor: colors.background,
        alignItems: 'center',
        justifyContent: 'center',
        padding: spacing.xl,
        gap: spacing.sm,
    },
    checkCircle: {
        width: 96,
        height: 96,
        borderRadius: 48,
        backgroundColor: colors.accent.payment,
        alignItems: 'center',
        justifyContent: 'center',
        marginBottom: spacing.sm,
    },
    checkGlyph: { fontSize: 52, fontWeight: '800', color: colors.textInverse },
    confirmTitle: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    confirmAmount: { fontSize: fontSize.display, fontWeight: '800', color: colors.accent.expense },
    confirmBody: { fontSize: fontSize.lg, fontWeight: '600', color: colors.textPrimary, textAlign: 'center' },
    confirmHint: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center' },
    confirmNudge: { alignSelf: 'stretch', marginTop: spacing.sm },
    confirmActions: { width: '100%', gap: spacing.sm, marginTop: spacing.lg },
});
