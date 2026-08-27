import { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { Badge } from '../src/components/ui/Badge';
import { TextInput as UiTextInput } from '../src/components/ui/TextInput';
import { EmptyState } from '../src/components/ui/EmptyState';
import { getAllCustomers } from '../src/db/customers';
import { insertLocalComplaint } from '../src/db/complaints';
import { syncManager } from '../src/sync/SyncManager';
import { validateComplaintForm, type ComplaintFormErrors } from '../src/utils/validation';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { LocalCustomer } from '../src/types/db';

type ComplaintCategory = 'operational' | 'customer';

const CATEGORIES: Array<{ value: ComplaintCategory; label: string; hint: string }> = [
    { value: 'operational', label: 'Operational', hint: 'An internal system or process issue — e.g. "my zone\'s list won\'t sync."' },
    { value: 'customer', label: 'Customer', hint: "A complaint relayed on a customer's behalf." },
];

/**
 * "Log a Complaint" — complaint-desk.md section 7. Reached one tap deeper
 * from Home (a secondary CTA card next to Record Expense), deliberately
 * NOT a 5th bottom tab. Same fields as the web submission form (section
 * 6): title (required), category chips, optional-looking-but-actually-
 * required collapsed description (see validateComplaintForm's doc comment
 * for why mobile hardens this beyond the web form's own validation), a
 * clearly separate `urgent` fast-path toggle, and a disabled photo
 * placeholder mirroring the web form's own "coming in a follow-up update"
 * state (resources/tsx/pages/Complaints/Create.tsx) — there is deliberately
 * NO self-declared priority/severity field anywhere on this screen; urgent
 * is the one narrow escape hatch, not a routine severity picker.
 *
 * Offline-safe: writes to the local `complaints` outbox immediately with
 * sync_status='queued', then shows the exact same amber "Saved · will
 * sync" confirmation badge as Record Payment/Record Expense — never a
 * different visual language for "saved but not yet synced."
 */
export default function LogComplaintScreen() {
    const router = useRouter();

    const [category, setCategory] = useState<ComplaintCategory>('operational');
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [descriptionOpen, setDescriptionOpen] = useState(false);
    const [urgent, setUrgent] = useState(false);

    const [customers, setCustomers] = useState<LocalCustomer[]>([]);
    const [customerSearch, setCustomerSearch] = useState('');
    const [selectedCustomer, setSelectedCustomer] = useState<LocalCustomer | null>(null);

    const [errors, setErrors] = useState<ComplaintFormErrors>({});
    const [submitting, setSubmitting] = useState(false);
    const [saved, setSaved] = useState(false);

    useFocusEffect(
        useCallback(() => {
            void getAllCustomers().then(setCustomers);
        }, []),
    );

    // Auto-expand the description disclosure if a submit attempt left an
    // error there, so the agent isn't left staring at a validation message
    // for a field that's still collapsed and invisible.
    useEffect(() => {
        if (errors.description) {
            setDescriptionOpen(true);
        }
    }, [errors.description]);

    function selectCategory(next: ComplaintCategory) {
        setCategory(next);
        setErrors((prev) => ({ ...prev, category: undefined, customer: undefined }));

        if (next === 'operational') {
            setSelectedCustomer(null);
            setCustomerSearch('');
        }
    }

    function resetForm() {
        setCategory('operational');
        setTitle('');
        setDescription('');
        setDescriptionOpen(false);
        setUrgent(false);
        setSelectedCustomer(null);
        setCustomerSearch('');
        setErrors({});
        setSaved(false);
    }

    const filteredCustomers = (() => {
        const term = customerSearch.trim().toLowerCase();

        if (!term) {
            return customers.slice(0, 20);
        }

        const needleDigits = term.replace(/\D/g, '');

        return customers
            .filter((customer) => {
                const nameMatch = customer.name.toLowerCase().includes(term);
                const phoneDigits = (customer.phone ?? '').replace(/\D/g, '');
                const phoneMatch = needleDigits.length > 0 && phoneDigits.includes(needleDigits);

                return nameMatch || phoneMatch;
            })
            .slice(0, 20);
    })();

    async function handleSubmit() {
        const result = validateComplaintForm({
            category,
            title,
            description,
            customerUuid: selectedCustomer?.uuid ?? null,
        });

        if (!result.valid) {
            setErrors(result.errors);
            return;
        }

        setErrors({});
        setSubmitting(true);

        try {
            await insertLocalComplaint({
                category,
                title: title.trim(),
                description: description.trim(),
                urgent,
                customer_uuid: category === 'customer' ? selectedCustomer?.uuid ?? null : null,
            });

            setSaved(true);

            // Best-effort immediate sync attempt — same "never blocks the
            // amber confirmation" trigger as Record Payment/Record Expense.
            void syncManager.notifyLocalWrite();
        } finally {
            setSubmitting(false);
        }
    }

    if (saved) {
        return (
            <View style={styles.confirmFlex}>
                <Badge label="Saved · will sync" tone="offline" />
                <Text style={styles.confirmTitle}>Complaint logged</Text>
                <Text style={styles.confirmBody}>{title}</Text>
                <Text style={styles.confirmHint}>
                    Saved on this device. It will sync automatically next time you're online — no action needed.
                </Text>
                <View style={styles.confirmActions}>
                    <Button title="Log another complaint" variant="secondary" onPress={resetForm} />
                    <Button title="Done" onPress={() => router.back()} />
                </View>
            </View>
        );
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Category</Text>
                <View style={styles.categoryChipRow}>
                    {CATEGORIES.map((option) => {
                        const active = category === option.value;

                        return (
                            <Pressable
                                key={option.value}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => selectCategory(option.value)}
                                style={[styles.categoryChip, active && styles.categoryChipActive]}
                            >
                                <Text style={[styles.categoryChipText, active && styles.categoryChipTextActive]}>
                                    {option.label}
                                </Text>
                            </Pressable>
                        );
                    })}
                </View>
                <Text style={styles.categoryHint}>{CATEGORIES.find((c) => c.value === category)?.hint}</Text>
                {errors.category ? <Text style={styles.errorText}>{errors.category}</Text> : null}
            </View>

            {category === 'customer' ? (
                <View style={styles.section}>
                    <Text style={styles.sectionLabel}>Customer</Text>
                    {selectedCustomer ? (
                        <Card style={styles.selectedCustomerCard}>
                            <View style={styles.selectedCustomerRow}>
                                <View style={styles.selectedCustomerText}>
                                    <Text style={styles.selectedCustomerName}>{selectedCustomer.name}</Text>
                                    {selectedCustomer.phone ? (
                                        <Text style={styles.selectedCustomerMeta}>{selectedCustomer.phone}</Text>
                                    ) : null}
                                </View>
                                <Button
                                    title="Change"
                                    variant="ghost"
                                    fullWidth={false}
                                    onPress={() => setSelectedCustomer(null)}
                                />
                            </View>
                        </Card>
                    ) : (
                        <>
                            <UiTextInput
                                placeholder="Search by name or phone"
                                value={customerSearch}
                                onChangeText={setCustomerSearch}
                                autoCapitalize="none"
                                autoCorrect={false}
                            />
                            <View style={styles.customerResults}>
                                {filteredCustomers.length === 0 ? (
                                    <EmptyState
                                        title="No matches"
                                        subtitle="Try a different name or phone number, or browse the Customers tab."
                                    />
                                ) : (
                                    filteredCustomers.map((row) => (
                                        <Card
                                            key={row.uuid}
                                            onPress={() => {
                                                setSelectedCustomer(row);
                                                setErrors((prev) => ({ ...prev, customer: undefined }));
                                            }}
                                            style={styles.customerRow}
                                        >
                                            <Text style={styles.customerRowName}>{row.name}</Text>
                                            {row.phone ? <Text style={styles.customerRowMeta}>{row.phone}</Text> : null}
                                        </Card>
                                    ))
                                )}
                            </View>
                        </>
                    )}
                    {errors.customer ? <Text style={styles.errorText}>{errors.customer}</Text> : null}
                </View>
            ) : null}

            <UiTextInput
                label="Title"
                placeholder="A short summary — e.g. Zone 3 customer list won't sync"
                value={title}
                onChangeText={(text) => {
                    setTitle(text);
                    setErrors((prev) => ({ ...prev, title: undefined }));
                }}
                error={errors.title}
            />

            <View style={styles.section}>
                <Pressable
                    accessibilityRole="button"
                    accessibilityState={{ expanded: descriptionOpen }}
                    onPress={() => setDescriptionOpen((value) => !value)}
                    hitSlop={8}
                    style={styles.disclosure}
                >
                    <Text style={styles.disclosureLabel}>
                        {descriptionOpen ? 'Hide description' : '+ Add description'}
                    </Text>
                </Pressable>
                {descriptionOpen ? (
                    <UiTextInput
                        placeholder="Anything that helps whoever picks this up…"
                        value={description}
                        onChangeText={(text) => {
                            setDescription(text);
                            setErrors((prev) => ({ ...prev, description: undefined }));
                        }}
                        multiline
                        numberOfLines={4}
                        style={styles.descriptionInput}
                        error={errors.description}
                    />
                ) : errors.description ? (
                    <Text style={styles.errorText}>{errors.description}</Text>
                ) : null}
            </View>

            {/* Urgent fast-path — deliberately separate, clearly labeled,
                never a graded priority/severity picker. See this screen's
                class doc and complaint-desk.md section 6/7. */}
            <Card accentColor={urgent ? colors.danger : undefined}>
                <View style={styles.urgentRow}>
                    <View style={styles.urgentText}>
                        <Text style={styles.urgentTitle}>This can't wait</Text>
                        <Text style={styles.urgentHint}>
                            Skips the routine queue and alerts staff right away. Most complaints don't need this —
                            they're automatically escalated if nobody acts within 48 hours.
                        </Text>
                    </View>
                    <Switch
                        value={urgent}
                        onValueChange={setUrgent}
                        trackColor={{ false: colors.border, true: colors.danger }}
                        accessibilityLabel="Mark this complaint as urgent"
                    />
                </View>
            </Card>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Photo (optional)</Text>
                <Pressable
                    disabled
                    style={styles.photoDisabled}
                    onPress={() =>
                        Alert.alert('Coming soon', 'Photo attachments are coming in a follow-up update.')
                    }
                >
                    <Text style={styles.photoDisabledText}>Photo attachments are coming in a follow-up update.</Text>
                </Pressable>
            </View>

            <Button title="Log complaint" size="large" loading={submitting} onPress={handleSubmit} />
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    section: { gap: spacing.sm },
    sectionLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    errorText: { fontSize: fontSize.xs, color: colors.danger },

    categoryChipRow: { flexDirection: 'row', gap: spacing.sm },
    categoryChip: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: spacing.md,
        // 48dp floor per mobile-app-react-native.md §6 — this is the first
        // control on the screen and gets tapped on every single submission
        // (unlike the description disclosure below, which stays hitSlop-only
        // since it's a once-per-form, easy-to-target link), so an actual
        // resize is the right trade-off, matching stage 1's identical
        // reasoning for the Customers list filter chips.
        minHeight: touchTarget.floor,
        borderRadius: radius.md,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    categoryChipActive: {
        borderColor: colors.accent.complaint,
        backgroundColor: '#FAE8FF', // light tint of accent.complaint (fuchsia-700)
    },
    categoryChipText: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    categoryChipTextActive: { color: colors.accent.complaint },
    categoryHint: { fontSize: fontSize.xs, color: colors.textSecondary },

    selectedCustomerCard: { gap: 0 },
    selectedCustomerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    selectedCustomerText: { flexShrink: 1 },
    selectedCustomerName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    selectedCustomerMeta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },

    customerResults: { gap: spacing.sm, maxHeight: 260 },
    customerRow: { gap: 2 },
    customerRowName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    customerRowMeta: { fontSize: fontSize.sm, color: colors.textSecondary },

    disclosure: { paddingVertical: spacing.xs },
    disclosureLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.complaint },
    descriptionInput: { minHeight: 88, textAlignVertical: 'top', paddingTop: spacing.md },

    urgentRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    urgentText: { flex: 1, gap: 2 },
    urgentTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    urgentHint: { fontSize: fontSize.xs, color: colors.textSecondary },

    photoDisabled: {
        borderWidth: 1,
        borderColor: colors.border,
        borderStyle: 'dashed',
        borderRadius: radius.md,
        padding: spacing.md,
        backgroundColor: colors.surfaceMuted,
    },
    photoDisabledText: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center' },

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
