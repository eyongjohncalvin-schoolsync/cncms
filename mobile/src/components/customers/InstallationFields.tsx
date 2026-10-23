import { useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { previewInstallation } from '../../api/customers';
import { extractErrorMessage } from '../../api/client';
import { Card } from '../ui/Card';
import { TextInput } from '../ui/TextInput';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import { PAYMENT_METHODS } from '../../utils/paymentCalc';
import { freeMonthsFor, takesMoney, toRequestFields, withFreeMonths, type InstallationFormState } from '../../utils/installation';
import type { CustomerServiceSelection, InstallationOptionsResponse, InstallationPreviewApi, ServiceCatalogueApi } from '../../types/api';

type Options = InstallationOptionsResponse['data'];

/**
 * Add Customer's "Installation & first payment" (docs/plans/installation-
 * and-promotions.md, phase 2) — the mobile twin of the web form's section:
 * offer, fee (an override needs a reason and approval), installation paid
 * now, months paid in advance, connection date and free months per ticked
 * service, with the server's live preview line and a duplicate warning.
 * Online-only, like the rest of Add Customer.
 */
export function InstallationFields({
    options,
    state,
    onChange,
    services,
    catalogue,
    name,
    phone,
    errors,
    onPreview,
}: {
    options: Options;
    state: InstallationFormState;
    onChange: (next: InstallationFormState) => void;
    services: CustomerServiceSelection[];
    catalogue: ServiceCatalogueApi[];
    name: string;
    phone: string;
    errors: Record<string, string | undefined>;
    onPreview: (preview: InstallationPreviewApi | null) => void;
}) {
    const [preview, setPreview] = useState<InstallationPreviewApi | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const latest = useRef(0);

    const offer = options.offers.find((o) => o.uuid === state.offerUuid) ?? null;
    const ticked = catalogue.filter((s) => services.some((row) => row.service_uuid === s.uuid && row.service_variant_uuid === null));
    const set = (patch: Partial<InstallationFormState>) => onChange({ ...state, ...patch });

    const body = JSON.stringify({ name, phone, services, ...toRequestFields(state) });

    useEffect(() => {
        const request = ++latest.current;
        const timer = setTimeout(() => {
            previewInstallation(JSON.parse(body))
                .then((response) => {
                    if (request === latest.current) {
                        setPreview(response.data);
                        setPreviewError(null);
                        onPreview(response.data);
                    }
                })
                .catch((error) => {
                    if (request === latest.current) {
                        setPreviewError(extractErrorMessage(error, 'Preview unavailable.'));
                        onPreview(null);
                    }
                });
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [body]);

    const needsReason = (preview?.needs_approval.length ?? 0) > 0;

    return (
        <Card>
            <Text style={styles.sectionTitle}>Installation &amp; first payment</Text>
            <Text style={styles.hint}>The month the customer joins is not billed. Free months start the month after the installation is fully paid.</Text>

            <TextInput label="Connected on (YYYY-MM-DD)" value={state.connectedOn} onChangeText={(text) => set({ connectedOn: text })} error={errors.connected_on} />

            {options.can_manage && (
                <>
                    <Text style={styles.label}>Installation offer</Text>
                    <View style={styles.chips}>
                        {[{ uuid: null as string | null, name: 'No offer', fee: '' }, ...options.offers].map((o) => {
                            const selected = o.uuid === state.offerUuid;

                            return (
                                <Pressable
                                    key={o.uuid ?? 'none'}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected }}
                                    onPress={() => set({ offerUuid: o.uuid, freeMonths: null, fee: '', paidNow: '' })}
                                    style={[styles.chip, selected && styles.chipSelected]}
                                >
                                    <Text style={[styles.chipLabel, selected && styles.chipLabelSelected]}>
                                        {o.name}
                                        {o.fee ? ` · ${Number(o.fee).toLocaleString()}` : ''}
                                    </Text>
                                </Pressable>
                            );
                        })}
                    </View>
                    {errors.installation_offer_uuid ? <Text style={styles.error}>{errors.installation_offer_uuid}</Text> : null}

                    <TextInput
                        label={offer ? `Installation fee (offer: ${Number(offer.fee).toLocaleString()})` : 'Installation fee'}
                        value={state.fee}
                        onChangeText={(text) => set({ fee: text })}
                        keyboardType="numeric"
                        error={errors.installation_fee}
                    />
                    {options.can_take_payment && (
                        <TextInput
                            label="Installation paid now"
                            value={state.paidNow}
                            onChangeText={(text) => set({ paidNow: text })}
                            keyboardType="numeric"
                            error={errors.installation_paid}
                        />
                    )}

                    {ticked.length > 0 && <Text style={styles.label}>Free months per service (up to {options.max_free_months})</Text>}
                    {ticked.map((service) => (
                        <TextInput
                            key={service.uuid}
                            label={service.name}
                            value={String(freeMonthsFor(offer, state.freeMonths, service.uuid))}
                            keyboardType="numeric"
                            onChangeText={(text) =>
                                set({ freeMonths: withFreeMonths(offer, state.freeMonths, ticked.map((s) => s.uuid), service.uuid, Number(text)) })
                            }
                        />
                    ))}
                    {errors.free_months ? <Text style={styles.error}>{errors.free_months}</Text> : null}
                </>
            )}

            {options.can_take_payment && (
                <TextInput
                    label="Months paid in advance"
                    value={state.advanceMonths ? String(state.advanceMonths) : ''}
                    onChangeText={(text) => set({ advanceMonths: Math.max(0, Number(text) || 0) })}
                    keyboardType="numeric"
                    error={errors.advance_months}
                />
            )}

            {(needsReason || state.reason !== '') && (
                <TextInput
                    label="Reason (needs admin/owner approval)"
                    value={state.reason}
                    onChangeText={(text) => set({ reason: text })}
                    error={errors.installation_reason}
                />
            )}

            {takesMoney(state) && (
                <>
                    <Text style={styles.label}>Paid by</Text>
                    <View style={styles.chips}>
                        {PAYMENT_METHODS.map((method) => {
                            const selected = method.value === state.method;

                            return (
                                <Pressable
                                    key={method.value}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected }}
                                    onPress={() => set({ method: method.value })}
                                    style={[styles.chip, selected && styles.chipSelected]}
                                >
                                    <Text style={[styles.chipLabel, selected && styles.chipLabelSelected]}>{method.label}</Text>
                                </Pressable>
                            );
                        })}
                    </View>
                    {state.method !== 'cash' && (
                        <TextInput
                            label="Transaction ID"
                            value={state.methodReference}
                            onChangeText={(text) => set({ methodReference: text })}
                            error={errors.method_reference}
                        />
                    )}
                </>
            )}

            <View style={styles.preview}>
                {previewError ? <Text style={styles.error}>{previewError}</Text> : null}
                {!previewError && preview ? (
                    <>
                        <Text style={styles.previewLine}>{preview.line}</Text>
                        {preview.advance ? (
                            <Text style={styles.hint}>
                                Advance {Number(preview.advance.amount).toLocaleString()} FCFA for {preview.advance.months} month(s)
                                {preview.advance.from_label ? ` (${preview.advance.from_label} – ${preview.advance.until_label})` : ''}
                            </Text>
                        ) : null}
                        {needsReason ? <Text style={styles.warning}>Needs approval: {preview.needs_approval.join(', ')}</Text> : null}
                        <Text style={styles.hint}>First billed month: {preview.billing_starts_label}</Text>
                    </>
                ) : null}
                {!previewError && !preview ? <Text style={styles.hint}>Working out the first bill…</Text> : null}
            </View>
        </Card>
    );
}

/** The "this may be an existing customer" warning with its confirm switch (item 11). */
export function DuplicateWarning({
    duplicates,
    confirmed,
    onConfirm,
}: {
    duplicates: InstallationPreviewApi['duplicates'];
    confirmed: boolean;
    onConfirm: (value: boolean) => void;
}) {
    if (duplicates.length === 0) {
        return null;
    }

    return (
        <Card>
            <Text style={styles.warningTitle}>This may be an existing customer</Text>
            {duplicates.map((d) => (
                <Text key={d.uuid} style={styles.hint}>
                    {d.name} — {d.phone ?? 'no phone'} · {d.zone ?? 'no zone'} · {d.status}
                </Text>
            ))}
            <View style={styles.switchRow}>
                <Switch value={confirmed} onValueChange={onConfirm} />
                <Text style={styles.switchLabel}>I checked — this is a different person (a new installation)</Text>
            </View>
        </Card>
    );
}

const styles = StyleSheet.create({
    sectionTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginBottom: spacing.xs },
    label: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary, marginTop: spacing.sm, marginBottom: spacing.xs },
    hint: { fontSize: fontSize.xs, color: colors.textSecondary, marginBottom: spacing.xs },
    error: { fontSize: fontSize.xs, color: colors.danger, marginBottom: spacing.xs },
    warning: { fontSize: fontSize.xs, color: colors.danger, marginTop: spacing.xs },
    warningTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.danger, marginBottom: spacing.xs },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.sm },
    chip: {
        paddingHorizontal: spacing.md,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    chipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipLabelSelected: { color: colors.textInverse },
    preview: { marginTop: spacing.md, padding: spacing.md, borderRadius: radius.lg, backgroundColor: colors.background, gap: spacing.xs },
    previewLine: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.sm, minHeight: touchTarget.floor },
    switchLabel: { flex: 1, fontSize: fontSize.sm, color: colors.textPrimary },
});
