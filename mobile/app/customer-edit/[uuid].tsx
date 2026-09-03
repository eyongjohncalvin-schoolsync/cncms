import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { fetchCustomerDetail, updateCustomer } from '../../src/api/customers';
import { fetchZones, type ZoneApi } from '../../src/api/zones';
import { fetchServiceCatalogue } from '../../src/api/services';
import { extractErrorMessage, isNetworkError } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { syncManager } from '../../src/sync/SyncManager';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { TextInput } from '../../src/components/ui/TextInput';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { ServicesPicker } from '../../src/components/customers/ServicesPicker';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing } from '../../src/theme/tokens';
import type { CustomerDetailApi, CustomerServiceSelection, ServiceCatalogueApi } from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting';

const LEVELS: Array<'normal' | 'Vip' | 'Operator'> = ['normal', 'Vip', 'Operator'];

/**
 * Edit Customer — mobile counterpart of resources/tsx/pages/Customers/
 * Edit.tsx. Same shape/gating/online-only reasoning as customer-create.tsx
 * — see that screen's doc comment; the one difference worth calling out
 * here is the service tick-list merge described below.
 *
 * services.md section 8's "(inactive)" rule: fetchServiceCatalogue() only
 * returns ACTIVE services/options, but a customer already holding one that
 * has since gone inactive must still see it ticked (labeled "(inactive)")
 * rather than have it silently vanish from the form. The web admin does
 * this merge server-side (CustomerController::serviceCatalogue()); there
 * is no customer-scoped variant of the mobile endpoint, so `buildCatalogue`
 * below does the same merge client-side from CustomerDetailApi.services.
 */
function buildCatalogue(activeCatalogue: ServiceCatalogueApi[], held: CustomerDetailApi['services']): ServiceCatalogueApi[] {
    const byUuid = new Map(activeCatalogue.map((s) => [s.uuid, s]));

    for (const row of held) {
        let service = byUuid.get(row.service_uuid);

        if (!service) {
            // Held but the base service itself is now inactive — synthesize
            // a minimal entry so it still renders (and stays ticked).
            service = {
                uuid: row.service_uuid,
                name: `${row.service_name} (inactive)`,
                description: null,
                price: row.price,
                is_default: false,
                variants: [],
            };
            byUuid.set(row.service_uuid, service);
        }

        if (row.service_variant_uuid && !service.variants.some((v) => v.uuid === row.service_variant_uuid)) {
            service.variants = [
                ...service.variants,
                { uuid: row.service_variant_uuid, name: `${row.service_variant_name} (inactive)`, price: row.price },
            ];
        }
    }

    return Array.from(byUuid.values());
}

export default function CustomerEditScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();
    const { can } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [zones, setZones] = useState<ZoneApi[]>([]);
    const [catalogue, setCatalogue] = useState<ServiceCatalogueApi[]>([]);

    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [location, setLocation] = useState('');
    const [level, setLevel] = useState<'normal' | 'Vip' | 'Operator'>('normal');
    const [zoneUuid, setZoneUuid] = useState<string | null>(null);
    const [services, setServices] = useState<CustomerServiceSelection[]>([]);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});

    const load = useCallback(() => {
        if (!uuid) {
            return;
        }

        if (!getSyncState().isOnline) {
            setPhase('offline');
            return;
        }

        setPhase('loading');
        setErrorMessage(null);

        Promise.all([fetchCustomerDetail(uuid), fetchZones(), fetchServiceCatalogue()])
            .then(([customerResponse, zonesResponse, catalogueResponse]) => {
                const customer = customerResponse.data;

                setZones(zonesResponse.data);
                setCatalogue(buildCatalogue(catalogueResponse.data, customer.services));

                setName(customer.name);
                setPhone(customer.phone ?? '');
                setLocation(customer.location ?? '');
                setLevel((customer.level as 'normal' | 'Vip' | 'Operator' | null) ?? 'normal');
                setZoneUuid(customer.zone_uuid);
                setServices(
                    customer.services.map((s) => ({
                        service_uuid: s.service_uuid,
                        service_variant_uuid: s.service_variant_uuid,
                        price: s.price,
                    })),
                );

                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load this customer."));
                    setPhase('error');
                }
            });
    }, [uuid]);

    useFocusEffect(
        useCallback(() => {
            load();

            return subscribeSyncState(() => {
                if (getSyncState().isOnline) {
                    setPhase((current) => (current === 'offline' ? 'loading' : current));
                }
            });
        }, [load]),
    );

    useEffect(() => {
        if (phase === 'loading' && getSyncState().isOnline) {
            load();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phase]);

    async function submit() {
        if (!uuid) {
            return;
        }

        const nextErrors: Record<string, string | undefined> = {};

        if (!name.trim()) {
            nextErrors.name = 'Name is required.';
        }
        if (!zoneUuid) {
            nextErrors.zone_uuid = 'Select a zone.';
        }
        if (services.length === 0) {
            nextErrors.services = 'Select at least one service.';
        }

        setErrors(nextErrors);

        if (Object.values(nextErrors).some(Boolean)) {
            return;
        }

        setPhase('submitting');

        try {
            await updateCustomer(uuid, {
                zone_uuid: zoneUuid as string,
                name: name.trim(),
                // Sent unconditionally (not `|| undefined`) so clearing the
                // field to blank actually clears it server-side — phone is
                // `sometimes, nullable` on UpdateCustomerRequest, so an
                // empty string is a valid, real "no phone on file" value,
                // not an omission.
                phone: phone.trim(),
                location: location.trim() || undefined,
                level,
                services,
            });

            void syncManager.syncNow('manual');

            Alert.alert('Customer updated', `${name.trim()} has been updated.`, [
                { text: 'OK', onPress: () => router.back() },
            ]);
        } catch (error) {
            setPhase('ready');
            Alert.alert('Could not save changes', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    if (!can('customers.update')) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Edit Customer' }} />
                <Card style={styles.notAuthorizedCard}>
                    <Text style={styles.notAuthorizedTitle}>Not authorized</Text>
                    <Text style={styles.notAuthorizedBody}>Your account can&apos;t edit customers.</Text>
                </Card>
            </View>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Edit Customer' }} />
                <ActivityIndicator size="large" color={colors.accent.customers} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Edit Customer' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Editing a customer needs a live connection. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Edit Customer' }} />
                <EmptyState title="Couldn't load this customer" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    const submitting = phase === 'submitting';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: 'Edit Customer' }} />

            <Card>
                <Text style={styles.sectionTitle}>Identity &amp; Location</Text>
                <TextInput
                    label="Full Name"
                    value={name}
                    onChangeText={(text) => {
                        setName(text);
                        setErrors((prev) => ({ ...prev, name: undefined }));
                    }}
                    error={errors.name}
                />
                <TextInput label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="6XX XXX XXX" />
                <TextInput label="Location (optional)" value={location} onChangeText={setLocation} placeholder="House / street / landmark" />
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Zone</Text>
                {errors.zone_uuid ? <Text style={styles.fieldError}>{errors.zone_uuid}</Text> : null}
                <View style={styles.zoneList}>
                    {zones.map((zone) => {
                        const selected = zone.uuid === zoneUuid;

                        return (
                            <Pressable
                                key={zone.uuid}
                                accessibilityRole="button"
                                accessibilityState={{ selected }}
                                onPress={() => {
                                    setZoneUuid(zone.uuid);
                                    setErrors((prev) => ({ ...prev, zone_uuid: undefined }));
                                }}
                                style={[styles.zoneChip, selected && styles.zoneChipSelected]}
                            >
                                <Text style={[styles.zoneChipLabel, selected && styles.zoneChipLabelSelected]}>{zone.name}</Text>
                            </Pressable>
                        );
                    })}
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Level</Text>
                <View style={styles.levelRow}>
                    {LEVELS.map((option) => {
                        const selected = option === level;

                        return (
                            <Pressable
                                key={option}
                                accessibilityRole="button"
                                accessibilityState={{ selected }}
                                onPress={() => setLevel(option)}
                                style={[styles.levelChip, selected && styles.levelChipSelected]}
                            >
                                <Text style={[styles.levelChipLabel, selected && styles.levelChipLabelSelected]}>{option}</Text>
                            </Pressable>
                        );
                    })}
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Services</Text>
                <ServicesPicker catalogue={catalogue} value={services} onChange={setServices} error={errors.services} />
            </Card>

            <Button
                title={submitting ? 'Saving…' : 'Save Changes'}
                size="large"
                loading={submitting}
                disabled={submitting}
                onPress={() => void submit()}
            />
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    sectionTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginBottom: spacing.sm },
    fieldError: { fontSize: fontSize.xs, color: colors.danger, marginBottom: spacing.sm },
    zoneList: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    zoneChip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    zoneChipSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    zoneChipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    zoneChipLabelSelected: { color: colors.textInverse },
    levelRow: { flexDirection: 'row', gap: spacing.sm },
    levelChip: {
        flex: 1,
        alignItems: 'center',
        paddingVertical: spacing.sm,
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    levelChipSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    levelChipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary, textTransform: 'capitalize' },
    levelChipLabelSelected: { color: colors.textInverse },
    notAuthorizedCard: { margin: spacing.lg },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
