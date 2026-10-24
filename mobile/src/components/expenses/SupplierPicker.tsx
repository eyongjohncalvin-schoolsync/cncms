import { useEffect, useMemo, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { TextInput } from '../ui/TextInput';
import { createSupplier } from '../../api/expenditures';
import { extractErrorMessage } from '../../api/client';
import { upsertSupplier } from '../../db/suppliers';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import type { LocalSupplier } from '../../types/db';

interface SupplierPickerProps {
    visible: boolean;
    suppliers: LocalSupplier[];
    selectedUuid: string | null;
    isOnline: boolean;
    onSelect: (supplierUuid: string | null) => void;
    onClose: () => void;
    /** Called after a supplier was created online (already cached locally). */
    onCreated: (supplierUuid: string) => void;
}

/**
 * Searchable supplier sheet for Record Expense. Works offline from the
 * local `suppliers` cache; a supplier the office hasn't approved yet is
 * still pickable but labelled "awaiting approval" (the server flags the
 * expense). "+ New supplier" is online-only — it needs the server to issue
 * the supplier's uuid — and the new supplier starts 'pending'.
 */
export function SupplierPicker({ visible, suppliers, selectedUuid, isOnline, onSelect, onClose, onCreated }: SupplierPickerProps) {
    const [query, setQuery] = useState('');
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [momo, setMomo] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (visible) {
            setQuery('');
            setCreating(false);
            setError(null);
        }
    }, [visible]);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return suppliers;
        }

        return suppliers.filter(
            (supplier) =>
                supplier.name.toLowerCase().includes(needle) ||
                (supplier.phone ?? '').includes(needle) ||
                (supplier.momo_number ?? '').includes(needle),
        );
    }, [suppliers, query]);

    function startCreate() {
        setName(query.trim());
        setPhone('');
        setMomo('');
        setError(null);
        setCreating(true);
    }

    async function handleCreate() {
        if (name.trim().length < 2 || saving) {
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await createSupplier({
                name: name.trim(),
                phone: phone.trim() || null,
                momo_number: momo.trim() || null,
            });
            await upsertSupplier(response.data);
            onCreated(response.data.uuid);
        } catch (createError) {
            setError(extractErrorMessage(createError, "Couldn't add the supplier."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal visible={visible} animationType="slide" presentationStyle="pageSheet" onRequestClose={onClose}>
            <SafeAreaView style={styles.flex} edges={['top', 'bottom']}>
                <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
                    <View style={styles.header}>
                        <Text style={styles.title}>{creating ? 'New supplier' : 'Who did you pay?'}</Text>
                        <Pressable accessibilityRole="button" onPress={onClose} style={styles.closeButton} hitSlop={8}>
                            <Text style={styles.closeText}>Close</Text>
                        </Pressable>
                    </View>

                    {creating ? (
                        <View style={styles.form}>
                            <TextInput label="Name" value={name} onChangeText={setName} placeholder="e.g. Ngwa Hardware" autoFocus />
                            <TextInput label="Phone (optional)" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
                            <TextInput
                                label="MoMo number (optional)"
                                value={momo}
                                onChangeText={setMomo}
                                keyboardType="phone-pad"
                            />
                            <Text style={styles.note}>The office approves new suppliers. You can use it straight away.</Text>
                            {error ? <Text style={styles.error}>{error}</Text> : null}
                            <Button
                                title="Add supplier"
                                size="large"
                                onPress={handleCreate}
                                loading={saving}
                                disabled={name.trim().length < 2}
                            />
                            <Button title="Back to list" variant="ghost" onPress={() => setCreating(false)} />
                        </View>
                    ) : (
                        <>
                            <View style={styles.searchWrap}>
                                <TextInput
                                    placeholder="Search name or number"
                                    value={query}
                                    onChangeText={setQuery}
                                    autoCorrect={false}
                                    returnKeyType="search"
                                />
                            </View>
                            <FlatList
                                data={filtered}
                                keyExtractor={(item) => item.uuid}
                                keyboardShouldPersistTaps="handled"
                                contentContainerStyle={styles.list}
                                ListHeaderComponent={
                                    <>
                                        {isOnline ? (
                                            <Pressable accessibilityRole="button" onPress={startCreate} style={[styles.row, styles.newRow]}>
                                                <Text style={styles.newText}>
                                                    + New supplier{query.trim() ? ` "${query.trim()}"` : ''}
                                                </Text>
                                            </Pressable>
                                        ) : (
                                            <Text style={styles.note}>Connect to the internet to add a new supplier.</Text>
                                        )}
                                        <Pressable
                                            accessibilityRole="button"
                                            accessibilityState={{ selected: selectedUuid === null }}
                                            onPress={() => onSelect(null)}
                                            style={[styles.row, selectedUuid === null && styles.rowSelected]}
                                        >
                                            <Text style={styles.rowName}>No supplier</Text>
                                            <Text style={styles.rowMeta}>Small purchase, no named seller</Text>
                                        </Pressable>
                                    </>
                                }
                                ListEmptyComponent={
                                    <Text style={styles.empty}>
                                        {suppliers.length === 0 ? 'No suppliers saved on this phone yet.' : 'No supplier matches that search.'}
                                    </Text>
                                }
                                renderItem={({ item }) => {
                                    const selected = item.uuid === selectedUuid;
                                    const meta = [item.phone, item.momo_number ? `MoMo ${item.momo_number}` : null, item.location]
                                        .filter(Boolean)
                                        .join(' · ');

                                    return (
                                        <Pressable
                                            accessibilityRole="button"
                                            accessibilityState={{ selected }}
                                            onPress={() => onSelect(item.uuid)}
                                            style={[styles.row, selected && styles.rowSelected]}
                                        >
                                            <View style={styles.rowTop}>
                                                <Text style={styles.rowName} numberOfLines={1}>
                                                    {item.name}
                                                </Text>
                                                {item.status === 'pending' ? <Badge label="Awaiting approval" tone="warning" /> : null}
                                            </View>
                                            {meta ? <Text style={styles.rowMeta}>{meta}</Text> : null}
                                        </Pressable>
                                    );
                                }}
                            />
                        </>
                    )}
                </KeyboardAvoidingView>
            </SafeAreaView>
        </Modal>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.md,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    title: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    closeButton: { minHeight: touchTarget.floor, justifyContent: 'center', paddingHorizontal: spacing.sm },
    closeText: { fontSize: fontSize.md, fontWeight: '700', color: colors.accent.expense },
    searchWrap: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
    list: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xxl },
    row: {
        minHeight: touchTarget.primary,
        justifyContent: 'center',
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.md,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        backgroundColor: colors.surface,
        gap: 2,
    },
    rowSelected: { borderColor: colors.accent.expense, borderWidth: 2, backgroundColor: '#F5EBFC' },
    rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    rowName: { flexShrink: 1, fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    rowMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
    newRow: { borderStyle: 'dashed', borderColor: colors.accent.expense, marginBottom: spacing.sm },
    newText: { fontSize: fontSize.md, fontWeight: '700', color: colors.accent.expense },
    note: { fontSize: fontSize.sm, color: colors.textSecondary, marginBottom: spacing.sm },
    empty: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center', paddingVertical: spacing.xl },
    form: { padding: spacing.lg, gap: spacing.md },
    error: { fontSize: fontSize.sm, color: colors.danger },
});
