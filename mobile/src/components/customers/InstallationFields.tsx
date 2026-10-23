import { useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { previewInstallation } from '../../api/customers';
import { extractErrorMessage } from '../../api/client';
import { Card } from '../ui/Card';
import { TextInput } from '../ui/TextInput';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import { PAYMENT_METHODS } from '../../utils/paymentCalc';
import {
    addMonths,
    defaultPlan,
    formatPeriod,
    installationMonth,
    isFreeMonth,
    syncPlans,
    takesMoney,
    toggleFreeMonth,
    toRequestFields,
    type InstallationFormState,
    type ServicePlan,
} from '../../utils/installation';
import type { CustomerServiceSelection, InstallationOptionsResponse, InstallationPreviewApi, ServiceCatalogueApi } from '../../types/api';

type Options = InstallationOptionsResponse['data'];

const CHIP_COUNT = 6;

/**
 * Add Customer's "Installation & first payment" (docs/plans/installation-
 * and-promotions.md, phase 2 + revision D15–D19) — the mobile twin of the
 * web form: per service ticked, ☐ Free months (month chips from the
 * installation month, that month ticked by default, one consecutive run)
 * and ☐ Paid upfront (months after the free ones, at that service's price),
 * each service's timeline free → paid → billed, the first billed month and
 * the total to collect now, from the server's live preview. Online-only.
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
    const firstMonth = installationMonth(state.connectedOn, options.today);
    const ticked = catalogue.filter((s) => services.some((row) => row.service_uuid === s.uuid && row.service_variant_uuid === null));
    const tickedKey = ticked.map((s) => s.uuid).join(',');
    const set = (patch: Partial<InstallationFormState>) => onChange({ ...state, ...patch });
    const setPlan = (next: ServicePlan) => set({ servicePlans: state.servicePlans.map((p) => (p.service_uuid === next.service_uuid ? next : p)) });

    // One plan per ticked service (D16 defaults for newly ticked ones).
    useEffect(() => {
        const plans = syncPlans(state.servicePlans, ticked.map((s) => s.uuid), firstMonth, offer);

        if (plans.length !== state.servicePlans.length || plans.some((p, i) => p !== state.servicePlans[i])) {
            set({ servicePlans: plans });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tickedKey]);

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
            <Text style={styles.hint}>
                For each service: free months, then months paid upfront, then the normal bill. Free months apply now only when the installation is
                fully paid today; otherwise an admin/owner approves them.
            </Text>

            <TextInput label="Connected on (YYYY-MM-DD)" value={state.connectedOn} onChangeText={(text) => set({ connectedOn: text })} error={errors.connected_on} />

            {options.can_manage && (
                <>
                    <Text style={styles.label}>Installation offer (preset)</Text>
                    <View style={styles.chips}>
                        {[{ uuid: null as string | null, name: 'No offer', fee: '' }, ...options.offers].map((o) => {
                            const selected = o.uuid === state.offerUuid;
                            const next = options.offers.find((x) => x.uuid === o.uuid) ?? null;

                            return (
                                <Pressable
                                    key={o.uuid ?? 'none'}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected }}
                                    onPress={() =>
                                        set({
                                            offerUuid: o.uuid,
                                            fee: '',
                                            servicePlans: state.servicePlans.map((p) => ({ ...defaultPlan(p.service_uuid, firstMonth, next), paid_months: p.paid_months })),
                                        })
                                    }
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

                    {ticked.map((service) => {
                        const plan = state.servicePlans.find((p) => p.service_uuid === service.uuid) ?? defaultPlan(service.uuid, firstMonth, offer);
                        const timeline = preview?.services.find((t) => t.service_uuid === service.uuid);

                        return (
                            <View key={service.uuid} style={styles.serviceBox}>
                                <Text style={styles.serviceName}>{service.name}</Text>
                                <View style={styles.switchRow}>
                                    <Switch
                                        value={plan.free_months > 0}
                                        onValueChange={(on) => setPlan(on ? { ...plan, free_from: firstMonth, free_months: 1 } : { ...plan, free_from: null, free_months: 0 })}
                                    />
                                    <Text style={styles.switchLabel}>Free months</Text>
                                </View>
                                {plan.free_months > 0 && (
                                    <>
                                        <View style={styles.chips}>
                                            {Array.from({ length: CHIP_COUNT }, (_, i) => addMonths(firstMonth, i)).map((month) => {
                                                const selected = isFreeMonth(plan, month);

                                                return (
                                                    <Pressable
                                                        key={month}
                                                        accessibilityRole="button"
                                                        accessibilityState={{ selected }}
                                                        onPress={() => setPlan(toggleFreeMonth(plan, month, firstMonth))}
                                                        style={[styles.chip, selected && styles.freeChipSelected]}
                                                    >
                                                        <Text style={[styles.chipLabel, selected && styles.chipLabelSelected]}>{formatPeriod(month)}</Text>
                                                    </Pressable>
                                                );
                                            })}
                                        </View>
                                        <Text style={styles.hint}>
                                            The installation month is free by default — tap more months to add them (up to {options.max_free_months}; more needs
                                            an admin/owner). Untick it to bill the installation month.
                                        </Text>
                                    </>
                                )}
                                {options.can_take_payment && (
                                    <>
                                        <View style={styles.switchRow}>
                                            <Switch value={plan.paid_months > 0} onValueChange={(on) => setPlan({ ...plan, paid_months: on ? 1 : 0 })} />
                                            <Text style={styles.switchLabel}>Paid upfront</Text>
                                        </View>
                                        {plan.paid_months > 0 && (
                                            <TextInput
                                                label={`Months paid upfront${timeline ? ` (${Number(timeline.paid_amount).toLocaleString()} FCFA)` : ''}`}
                                                value={String(plan.paid_months)}
                                                keyboardType="numeric"
                                                onChangeText={(text) => setPlan({ ...plan, paid_months: Math.max(0, Math.min(24, Number(text) || 0)) })}
                                            />
                                        )}
                                    </>
                                )}
                                {timeline ? (
                                    <Text style={styles.timeline}>
                                        {timeline.free_from ? `Free ${formatPeriod(timeline.free_from)}–${formatPeriod(timeline.free_until ?? '')} → ` : ''}
                                        {timeline.paid_from ? `paid ${formatPeriod(timeline.paid_from)}–${formatPeriod(timeline.paid_until ?? '')} → ` : ''}
                                        billed from {timeline.first_billed ? formatPeriod(timeline.first_billed) : '—'}
                                    </Text>
                                ) : null}
                            </View>
                        );
                    })}
                    {errors.service_plans ? <Text style={styles.error}>{errors.service_plans}</Text> : null}
                </>
            )}

            {(needsReason || state.reason !== '') && (
                <TextInput label="Reason (needs admin/owner approval)" value={state.reason} onChangeText={(text) => set({ reason: text })} error={errors.installation_reason} />
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
                        <TextInput label="Transaction ID" value={state.methodReference} onChangeText={(text) => set({ methodReference: text })} error={errors.method_reference} />
                    )}
                </>
            )}

            <View style={styles.preview}>
                {previewError ? <Text style={styles.error}>{previewError}</Text> : null}
                {!previewError && preview ? (
                    <>
                        <Text style={styles.previewLine}>Collect now: {Number(preview.collect_now).toLocaleString()} FCFA</Text>
                        {Number(preview.upfront_amount) > 0 ? (
                            <Text style={styles.hint}>Includes {Number(preview.upfront_amount).toLocaleString()} FCFA paid upfront</Text>
                        ) : null}
                        {needsReason ? <Text style={styles.warning}>Needs approval: {preview.needs_approval.join(', ')}</Text> : null}
                        <Text style={styles.hint}>
                            Installation month {preview.installation_month_label} · first billed month {preview.first_bill_label ?? '—'}
                        </Text>
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
    freeChipSelected: { backgroundColor: colors.accent.payment, borderColor: colors.accent.payment },
    chipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipLabelSelected: { color: colors.textInverse },
    serviceBox: { marginTop: spacing.md, padding: spacing.md, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, gap: spacing.xs },
    serviceName: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    timeline: { fontSize: fontSize.xs, color: colors.textPrimary, marginTop: spacing.xs },
    preview: { marginTop: spacing.md, padding: spacing.md, borderRadius: radius.lg, backgroundColor: colors.background, gap: spacing.xs },
    previewLine: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: touchTarget.floor },
    switchLabel: { flex: 1, fontSize: fontSize.sm, color: colors.textPrimary },
});
