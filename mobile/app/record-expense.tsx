import { useCallback, useState } from 'react';
import { View, Text, ScrollView, Pressable, Image, StyleSheet, Alert, Switch } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { Badge } from '../src/components/ui/Badge';
import { TextInput } from '../src/components/ui/TextInput';
import { getExpenseCategories } from '../src/db/categories';
import { insertLocalExpenditure } from '../src/db/expenditures';
import { syncManager } from '../src/sync/SyncManager';
import { glyphForCategoryIcon } from '../src/utils/categoryIcons';
import { validateBonRequestForm, type BonRequestFormErrors } from '../src/utils/validation';
import { formatFcfa, todayDateOnly } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { LocalExpenseCategory } from '../src/types/db';

/**
 * Request a bon (docs/plans/expenditures-and-spending-controls.md, item 2).
 * Every franc that leaves the company goes through a numbered bon, so the
 * phone no longer records an expense: it asks for a bon — category, amount,
 * who is paid, what for, the day it is needed, an optional receipt photo,
 * and "already paid from my collections" (then, once approved, it is settled
 * at the agent's next cash handover so the cash count still matches).
 * Saved to the local outbox first (works offline); SyncManager sends it as
 * a `bon_requests` item and it waits for approval like any bon.
 */
export default function RequestBonScreen() {
    const router = useRouter();

    const [categories, setCategories] = useState<LocalExpenseCategory[]>([]);
    const [categoryUuid, setCategoryUuid] = useState<string | null>(null);
    const [amountText, setAmountText] = useState('');
    const [payeeName, setPayeeName] = useState('');
    const [description, setDescription] = useState('');
    const [dateText, setDateText] = useState(todayDateOnly());
    const [fromCollections, setFromCollections] = useState(false);
    const [photoUri, setPhotoUri] = useState<string | null>(null);
    const [errors, setErrors] = useState<BonRequestFormErrors>({});
    const [submitting, setSubmitting] = useState(false);
    const [saved, setSaved] = useState(false);
    const [savedSummary, setSavedSummary] = useState<{ category: string; amount: number } | null>(null);

    useFocusEffect(
        useCallback(() => {
            void getExpenseCategories().then(setCategories);
        }, []),
    );

    async function handleTakePhoto() {
        const permission = await ImagePicker.requestCameraPermissionsAsync();

        if (!permission.granted) {
            Alert.alert(
                'Camera permission needed',
                'Allow camera access in your device settings to attach a receipt photo.',
            );
            return;
        }

        const result = await ImagePicker.launchCameraAsync({ quality: 0.5, allowsEditing: false });

        if (!result.canceled && result.assets && result.assets.length > 0) {
            setPhotoUri(result.assets[0].uri);
        }
    }

    function resetForm() {
        setCategoryUuid(null);
        setAmountText('');
        setPayeeName('');
        setDescription('');
        setDateText(todayDateOnly());
        setFromCollections(false);
        setPhotoUri(null);
        setErrors({});
        setSaved(false);
        setSavedSummary(null);
    }

    async function handleSubmit() {
        const result = validateBonRequestForm({ categoryUuid, amountText, payeeName, purpose: description, neededOnText: dateText, today: todayDateOnly() });

        if (!result.valid) {
            setErrors(result.errors);
            return;
        }

        setErrors({});
        setSubmitting(true);

        try {
            await insertLocalExpenditure({
                category_uuid: categoryUuid as string,
                amount: result.amount,
                description: description.trim(),
                spent_at: dateText,
                receipt_local_uri: photoUri,
                payee_name: payeeName.trim(),
                from_collections: fromCollections,
            });

            const category = categories.find((c) => c.uuid === categoryUuid);
            setSavedSummary({ category: category?.name ?? 'Bon', amount: result.amount });
            setSaved(true);

            // Best-effort immediate sync attempt (mobile-app-react-native.md
            // §2's "immediately after each local write, if online" trigger)
            // — never awaited, so the calm offline confirmation below never
            // waits on network.
            void syncManager.syncNow('manual');
        } finally {
            setSubmitting(false);
        }
    }

    if (saved && savedSummary) {
        return (
            <View style={styles.confirmFlex}>
                <Badge label="Saved · will sync" tone="offline" />
                <Text style={styles.confirmTitle}>Bon requested</Text>
                <Text style={styles.confirmBody}>
                    {savedSummary.category} — {formatFcfa(savedSummary.amount)}
                </Text>
                <Text style={styles.confirmHint}>
                    Saved on this device. It goes to the office next time you're online and waits for approval — you'll be notified.
                </Text>
                <View style={styles.confirmActions}>
                    <Button title="Request another bon" variant="secondary" onPress={resetForm} />
                    <Button title="Done" onPress={() => router.back()} />
                </View>
            </View>
        );
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Category</Text>
                {categories.length === 0 ? (
                    <Text style={styles.emptyHint}>Loading categories…</Text>
                ) : (
                    <View style={styles.categoryList}>
                        {categories.map((category) => {
                            const selected = category.uuid === categoryUuid;

                            return (
                                <Pressable
                                    key={category.uuid}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected }}
                                    onPress={() => {
                                        setCategoryUuid(category.uuid);
                                        setErrors((prev) => ({ ...prev, category: undefined }));
                                    }}
                                    style={[styles.categoryRow, selected && styles.categoryRowSelected]}
                                >
                                    <View style={[styles.categoryGlyph, selected && styles.categoryGlyphSelected]}>
                                        <Text style={styles.categoryGlyphText}>
                                            {glyphForCategoryIcon(category.icon, category.name)}
                                        </Text>
                                    </View>
                                    <Text style={[styles.categoryName, selected && styles.categoryNameSelected]}>
                                        {category.name}
                                    </Text>
                                </Pressable>
                            );
                        })}
                    </View>
                )}
                {errors.category ? <Text style={styles.errorText}>{errors.category}</Text> : null}
            </View>

            <TextInput
                label="Amount (FCFA)"
                keyboardType="numeric"
                placeholder="0"
                value={amountText}
                onChangeText={(text) => {
                    setAmountText(text);
                    setErrors((prev) => ({ ...prev, amount: undefined }));
                }}
                error={errors.amount}
                style={styles.amountInput}
            />

            <TextInput
                label="Pay to"
                placeholder="Who receives the money"
                value={payeeName}
                onChangeText={(text) => {
                    setPayeeName(text);
                    setErrors((prev) => ({ ...prev, payee: undefined }));
                }}
                error={errors.payee}
            />

            <TextInput
                label="What for"
                placeholder="e.g. Fuel for zone rounds — this is the paper trail"
                value={description}
                onChangeText={(text) => {
                    setDescription(text);
                    setErrors((prev) => ({ ...prev, purpose: undefined }));
                }}
                error={errors.purpose}
                multiline
                numberOfLines={3}
                style={styles.descriptionInput}
            />

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Needed on</Text>
                <View style={styles.dateChipRow}>
                    <Pressable
                        onPress={() => setDateText(todayDateOnly())}
                        style={[styles.dateChip, dateText === todayDateOnly() && styles.dateChipActive]}
                    >
                        <Text style={[styles.dateChipText, dateText === todayDateOnly() && styles.dateChipTextActive]}>
                            Today
                        </Text>
                    </Pressable>
                </View>
                <TextInput
                    label="Or enter a date (YYYY-MM-DD)"
                    placeholder={todayDateOnly()}
                    value={dateText}
                    onChangeText={(text) => {
                        setDateText(text);
                        setErrors((prev) => ({ ...prev, neededOn: undefined }));
                    }}
                    error={errors.neededOn}
                    autoCapitalize="none"
                    autoCorrect={false}
                />
            </View>

            <View style={styles.switchRow}>
                <View style={styles.switchText}>
                    <Text style={styles.sectionLabel}>Already paid from my collections</Text>
                    <Text style={styles.emptyHint}>
                        Once approved, it is settled at your next cash handover — hand over the rest of the cash.
                    </Text>
                </View>
                <Switch value={fromCollections} onValueChange={setFromCollections} accessibilityLabel="Already paid from my collections" />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Receipt photo (optional)</Text>
                {photoUri ? (
                    <Card>
                        <Image source={{ uri: photoUri }} style={styles.photoPreview} />
                        <View style={styles.photoActions}>
                            <Button title="Retake" variant="ghost" fullWidth={false} onPress={handleTakePhoto} />
                            <Button title="Remove" variant="ghost" fullWidth={false} onPress={() => setPhotoUri(null)} />
                        </View>
                    </Card>
                ) : (
                    <Button title="Take photo" variant="secondary" onPress={handleTakePhoto} />
                )}
            </View>

            <Button title="Request the bon" size="large" loading={submitting} onPress={handleSubmit} />
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    section: { gap: spacing.sm },
    sectionLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    emptyHint: { fontSize: fontSize.sm, color: colors.textSecondary },
    errorText: { fontSize: fontSize.xs, color: colors.danger },
    categoryList: { gap: spacing.sm },
    categoryRow: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.md,
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.md,
        padding: spacing.md,
        backgroundColor: colors.surface,
    },
    categoryRowSelected: {
        borderColor: colors.accent.expense,
        backgroundColor: '#F5EBFC', // light tint of accent.expense (purple-700)
    },
    categoryGlyph: {
        width: 40,
        height: 40,
        borderRadius: radius.lg,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.surfaceMuted,
    },
    categoryGlyphSelected: {
        backgroundColor: colors.accent.expense,
    },
    categoryGlyphText: { fontSize: fontSize.lg },
    categoryName: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary },
    categoryNameSelected: { color: colors.accent.expense },
    amountInput: { fontSize: fontSize.xl, fontWeight: '700' },
    descriptionInput: { minHeight: 72, textAlignVertical: 'top', paddingTop: spacing.md },
    switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    switchText: { flex: 1, gap: spacing.xs },
    dateChipRow: { flexDirection: 'row', gap: spacing.sm },
    // minHeight/justifyContent added 2026-08-27: padding alone put this
    // under the 48dp touch-target floor (mobile-app-react-native.md §6) —
    // "Today"/"Yesterday" are tapped constantly (every expense entry), so
    // unlike record-payment's rarer disclosure links, this one gets an
    // actual size fix rather than just hitSlop.
    dateChip: {
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    dateChipActive: {
        // Deliberately NOT colors.accent.expense here — white text on that
        // purple-700 measures ~6.98:1, a hair under this app's own AAA 7:1
        // minimum (mobile-app-react-native.md §6). This purple-800 shade
        // clears it (~8.7:1) while staying visibly "the same expense
        // purple." Scoped to just this one white-on-fill pairing, not a
        // change to the shared accent.expense token — that token is used
        // elsewhere on this same screen as a text/border color (e.g.
        // categoryRowSelected), where the contrast math is different and
        // already passes.
        borderColor: '#6B21A8',
        backgroundColor: '#6B21A8',
    },
    dateChipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    dateChipTextActive: { color: colors.textInverse },
    photoPreview: { width: '100%', height: 180, borderRadius: radius.md, backgroundColor: colors.surfaceMuted },
    photoActions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
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
    confirmActions: { width: '100%', gap: spacing.sm },
});
