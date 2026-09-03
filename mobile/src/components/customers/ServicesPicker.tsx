import { Pressable, StyleSheet, Text, View } from 'react-native';
import { TextInput } from '../ui/TextInput';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import { formatFcfa } from '../../utils/format';
import type { CustomerServiceSelection, ServiceCatalogueApi } from '../../types/api';

interface ServicesPickerProps {
    catalogue: ServiceCatalogueApi[];
    value: CustomerServiceSelection[];
    onChange: (next: CustomerServiceSelection[]) => void;
    error?: string;
}

/**
 * The customer add/edit form's "Services" block (services.md sections
 * 6-8), mobile counterpart of resources/tsx/components/customers/
 * ServicesPicker.tsx — same behavior, RN primitives. Every catalogue
 * service is a tickable row; ticking one reveals a price input (prefilled
 * from the catalogue price) and, if that service offers any options
 * ("channels" — section 4), a nested tick-list of those, each its own
 * price line. Unticking a service drops it AND every option ticked under
 * it in the same update — you can't hold "the news channel option"
 * without the base service (App\Services\CustomerSubscriptionService
 * enforces this server-side too; this UI just never lets you build an
 * invalid selection).
 *
 * `catalogue` is expected to already include anything the customer
 * currently holds, even if it's gone inactive since — the screen that
 * renders this merges CustomerDetailApi.services into the live
 * fetchServiceCatalogue() result before passing it down (services.md
 * section 8's "(inactive)" tag requirement), so this component itself
 * doesn't need to know about active/inactive at all.
 */
export function ServicesPicker({ catalogue, value, onChange, error }: ServicesPickerProps) {
    const baseSelection = (serviceUuid: string) =>
        value.find((v) => v.service_uuid === serviceUuid && v.service_variant_uuid === null);
    const variantSelection = (variantUuid: string) => value.find((v) => v.service_variant_uuid === variantUuid);

    function toggleService(service: ServiceCatalogueApi, checked: boolean) {
        if (checked) {
            onChange([...value, { service_uuid: service.uuid, service_variant_uuid: null, price: service.price }]);

            return;
        }

        onChange(value.filter((v) => v.service_uuid !== service.uuid));
    }

    function toggleVariant(service: ServiceCatalogueApi, variantUuid: string, price: string, checked: boolean) {
        if (checked) {
            onChange([...value, { service_uuid: service.uuid, service_variant_uuid: variantUuid, price }]);

            return;
        }

        onChange(value.filter((v) => v.service_variant_uuid !== variantUuid));
    }

    function setPrice(match: (v: CustomerServiceSelection) => boolean, price: string) {
        onChange(value.map((v) => (match(v) ? { ...v, price } : v)));
    }

    const total = value.reduce((sum, v) => sum + (parseFloat(v.price) || 0), 0);

    return (
        <View style={styles.container}>
            {catalogue.map((service) => {
                const selection = baseSelection(service.uuid);
                const checked = selection !== undefined;

                return (
                    <View key={service.uuid} style={[styles.serviceCard, checked && styles.serviceCardChecked]}>
                        <Pressable
                            accessibilityRole="checkbox"
                            accessibilityState={{ checked }}
                            onPress={() => toggleService(service, !checked)}
                            style={styles.serviceRow}
                        >
                            <View style={[styles.checkbox, checked && styles.checkboxChecked]}>
                                {checked ? <Text style={styles.checkmark}>✓</Text> : null}
                            </View>
                            <View style={styles.serviceLabel}>
                                <Text style={styles.serviceName}>{service.name}</Text>
                                {service.description ? <Text style={styles.serviceDescription}>{service.description}</Text> : null}
                            </View>
                        </Pressable>

                        {checked ? (
                            <View style={styles.priceRow}>
                                <Text style={styles.priceLabel}>Price</Text>
                                <TextInput
                                    keyboardType="numeric"
                                    value={selection.price}
                                    onChangeText={(text) =>
                                        setPrice((v) => v.service_uuid === service.uuid && v.service_variant_uuid === null, text)
                                    }
                                    style={styles.priceInput}
                                />
                                <Text style={styles.priceCurrency}>FCFA</Text>
                            </View>
                        ) : null}

                        {checked && parseFloat(selection.price) === 0 ? (
                            <Text style={styles.zeroPriceWarning}>No price set for this service yet.</Text>
                        ) : null}

                        {checked && service.variants.length > 0 ? (
                            <View style={styles.variantsBlock}>
                                {service.variants.map((variant) => {
                                    const vSelection = variantSelection(variant.uuid);
                                    const vChecked = vSelection !== undefined;

                                    return (
                                        <View key={variant.uuid}>
                                            <Pressable
                                                accessibilityRole="checkbox"
                                                accessibilityState={{ checked: vChecked }}
                                                onPress={() => toggleVariant(service, variant.uuid, variant.price, !vChecked)}
                                                style={styles.variantRow}
                                            >
                                                <View style={[styles.checkboxSmall, vChecked && styles.checkboxChecked]}>
                                                    {vChecked ? <Text style={styles.checkmarkSmall}>✓</Text> : null}
                                                </View>
                                                <Text style={styles.variantName}>{variant.name}</Text>
                                            </Pressable>

                                            {vChecked ? (
                                                <View style={styles.variantPriceRow}>
                                                    <TextInput
                                                        keyboardType="numeric"
                                                        value={vSelection.price}
                                                        onChangeText={(text) =>
                                                            setPrice((v) => v.service_variant_uuid === variant.uuid, text)
                                                        }
                                                        style={styles.variantPriceInput}
                                                    />
                                                    <Text style={styles.priceCurrency}>FCFA</Text>
                                                </View>
                                            ) : null}
                                        </View>
                                    );
                                })}
                            </View>
                        ) : null}
                    </View>
                );
            })}

            {error ? <Text style={styles.errorText}>{error}</Text> : null}

            <View style={styles.totalRow}>
                <Text style={styles.totalLabel}>Total monthly bill</Text>
                <Text style={styles.totalValue}>{formatFcfa(total)}</Text>
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    container: { gap: spacing.sm },
    serviceCard: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.lg,
        padding: spacing.md,
        backgroundColor: colors.surface,
    },
    serviceCardChecked: {
        borderColor: colors.accent.customers,
        backgroundColor: colors.background,
    },
    // minHeight/justifyContent: 'center' added per mobile-app-react-native.md
    // §6's 48dp touch-target floor — this Pressable's un-padded content
    // (a 22px checkbox + a name label, no description on many services)
    // measured well under 48dp on its own; ticking a service is this
    // screen's single most-tapped interaction, so this gets an actual
    // resize (the "resize what's tapped constantly" rule from the
    // Customers-list/Log-a-Complaint touch-target audits), not hitSlop.
    serviceRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: touchTarget.floor },
    checkbox: {
        width: 22,
        height: 22,
        borderRadius: 6,
        borderWidth: 2,
        borderColor: colors.border,
        alignItems: 'center',
        justifyContent: 'center',
    },
    checkboxSmall: {
        width: 18,
        height: 18,
        borderRadius: 5,
        borderWidth: 2,
        borderColor: colors.border,
        alignItems: 'center',
        justifyContent: 'center',
    },
    checkboxChecked: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    checkmark: { color: colors.textInverse, fontSize: fontSize.sm, fontWeight: '800' },
    checkmarkSmall: { color: colors.textInverse, fontSize: fontSize.xs, fontWeight: '800' },
    serviceLabel: { flex: 1, gap: 2 },
    serviceName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    serviceDescription: { fontSize: fontSize.xs, color: colors.textSecondary },
    priceRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.sm, marginLeft: 30 },
    priceLabel: { fontSize: fontSize.xs, color: colors.textSecondary, width: 40 },
    priceInput: { flex: 1, marginBottom: 0 },
    priceCurrency: { fontSize: fontSize.xs, color: colors.textSecondary },
    zeroPriceWarning: { fontSize: fontSize.xs, color: colors.status.offlineFg, marginTop: spacing.xs, marginLeft: 30 },
    variantsBlock: {
        marginTop: spacing.sm,
        marginLeft: 30,
        paddingLeft: spacing.sm,
        borderLeftWidth: 2,
        borderLeftColor: colors.border,
        gap: spacing.sm,
    },
    // Same touch-target reasoning as serviceRow above — ticking an option is
    // a real, frequent interaction on any service that has them.
    variantRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: touchTarget.floor },
    variantName: { fontSize: fontSize.sm, color: colors.textPrimary, flex: 1 },
    variantPriceRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.xs, marginLeft: 26 },
    variantPriceInput: { flex: 1, marginBottom: 0 },
    errorText: { fontSize: fontSize.xs, color: colors.danger },
    totalRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        borderRadius: radius.lg,
        backgroundColor: colors.surfaceMuted,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        marginTop: spacing.xs,
    },
    totalLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    totalValue: { fontSize: fontSize.md, fontWeight: '800', color: colors.textPrimary },
});
