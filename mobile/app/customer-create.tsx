import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useRouter } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { createCustomer } from '../src/api/customers';
import { fetchZones, type ZoneApi } from '../src/api/zones';
import { fetchServiceCatalogue } from '../src/api/services';
import { extractErrorMessage, isNetworkError } from '../src/api/client';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { syncManager } from '../src/sync/SyncManager';
import { Card } from '../src/components/ui/Card';
import { Button } from '../src/components/ui/Button';
import { TextInput } from '../src/components/ui/TextInput';
import { EmptyState } from '../src/components/ui/EmptyState';
import { ServicesPicker } from '../src/components/customers/ServicesPicker';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';
import type { CustomerServiceSelection, ServiceCatalogueApi } from '../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting';

const LEVELS: Array<'normal' | 'Vip' | 'Operator'> = ['normal', 'Vip', 'Operator'];

/**
 * Add Customer — mobile counterpart of resources/tsx/pages/Customers/
 * Create.tsx, added 2026-09-03 (customer create/edit wasn't on mobile at
 * all before). Gated by `customers.create` (CustomerPolicy), which is
 * NOT seeded to `agent` by default (DefaultRolesSeeder) — so in practice
 * this screen serves a `manager`/`admin`/`super` caller using the mobile
 * app, not the typical field agent. The entry point on the Customers list
 * (app/(tabs)/customers/index.tsx) is hidden the same way, but this screen
 * re-checks and shows a "Not authorized" card if reached directly, same
 * defensive pattern as disconnect/reconnect.
 *
 * Online-only, same reasoning as reconnect/disconnect/createCustomer()'s
 * own doc comment: a new customer needs a server-issued uuid and its
 * zone/services validated against live data — this does NOT queue offline.
 *
 * Fetches the zone list (fetchZones()) and the service catalogue
 * (fetchServiceCatalogue()) once on open — both small, rarely-changing
 * lists, same "plain live call, no cache" choice as app/zones.tsx and the
 * web Create.tsx's Inertia props.
 */
export default function CustomerCreateScreen() {
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

    function load() {
        if (!getSyncState().isOnline) {
            setPhase('offline');
            return;
        }

        setPhase('loading');
        setErrorMessage(null);

        Promise.all([fetchZones(), fetchServiceCatalogue()])
            .then(([zonesResponse, catalogueResponse]) => {
                setZones(zonesResponse.data);
                setCatalogue(catalogueResponse.data);

                const defaultService = catalogueResponse.data.find((s) => s.is_default);
                setServices(
                    defaultService
                        ? [{ service_uuid: defaultService.uuid, service_variant_uuid: null, price: defaultService.price }]
                        : [],
                );

                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load zones and services."));
                    setPhase('error');
                }
            });
    }

    useEffect(() => {
        load();

        return subscribeSyncState(() => {
            if (getSyncState().isOnline) {
                // A retry only matters while we're stuck on the offline
                // screen — re-running `load()` mid-form-fill would wipe
                // what the user already typed.
                setPhase((current) => (current === 'offline' ? 'loading' : current));
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (phase === 'loading' && getSyncState().isOnline) {
            load();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phase]);

    async function submit() {
        const nextErrors: Record<string, string | undefined> = {};

        if (!name.trim()) {
            nextErrors.name = 'Name is required.';
        }
        if (!phone.trim()) {
            nextErrors.phone = 'Phone is required.';
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
            await createCustomer({
                zone_uuid: zoneUuid as string,
                name: name.trim(),
                phone: phone.trim(),
                location: location.trim() || undefined,
                level,
                services,
            });

            // Best-effort — pulls the new customer into every device's cache
            // (including this one) sooner than the next natural sync tick.
            void syncManager.syncNow('manual');

            Alert.alert('Customer added', `${name.trim()} has been added.`, [
                { text: 'OK', onPress: () => router.back() },
            ]);
        } catch (error) {
            setPhase('ready');
            Alert.alert('Could not add customer', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    if (!can('customers.create')) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Add Customer' }} />
                <Card style={styles.notAuthorizedCard}>
                    <Text style={styles.notAuthorizedTitle}>Not authorized</Text>
                    <Text style={styles.notAuthorizedBody}>Your account can&apos;t add new customers.</Text>
                </Card>
            </View>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Add Customer' }} />
                <ActivityIndicator size="large" color={colors.accent.customers} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Add Customer' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Adding a customer needs a live connection to check the zone and service list. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Add Customer' }} />
                <EmptyState title="Couldn't load this form" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    const submitting = phase === 'submitting';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: 'Add Customer' }} />

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
                    placeholder="e.g., Ekema Divine"
                />
                <TextInput
                    label="Phone"
                    value={phone}
                    onChangeText={(text) => {
                        setPhone(text);
                        setErrors((prev) => ({ ...prev, phone: undefined }));
                    }}
                    error={errors.phone}
                    keyboardType="phone-pad"
                    placeholder="6XX XXX XXX"
                />
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
                title={submitting ? 'Adding…' : 'Add Customer'}
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
