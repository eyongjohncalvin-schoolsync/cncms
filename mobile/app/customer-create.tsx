import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useRouter } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { createCustomer, fetchInstallationOptions } from '../src/api/customers';
import { DuplicateWarning, InstallationFields } from '../src/components/customers/InstallationFields';
import { initialInstallationState, toRequestFields, type InstallationFormState } from '../src/utils/installation';
import {
    buildFieldInstallationItem,
    fieldRegistrationErrors,
    installationPaidCents,
    servicePrices,
    upfrontCents,
    type FieldInstallationReceipt,
} from '../src/utils/fieldInstallation';
import { cacheFormData, getCachedFormData, refreshFormData } from '../src/db/formCache';
import { insertLocalInstallation } from '../src/db/installations';
import { findLocalDuplicates } from '../src/db/customers';
import { getMyZoneUuids } from '../src/db/zones';
import { issueFieldReceipt, type IssuedFieldReceipt } from '../src/fieldReceipts/receiptStock';
import { formatReceiptCode, receiptCheckUrl } from '../src/utils/receiptCode';
import { generateUuid } from '../src/utils/uuid';
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
import { ReceiptDeliveryFields } from '../src/components/customers/ReceiptDeliveryFields';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { CustomerServiceSelection, InstallationOptionsResponse, InstallationPreviewApi, PreferredChannel, ServiceCatalogueApi } from '../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting' | 'registered';

/**
 * 'online' — the office path: saved straight to the server (customers.create).
 * 'field' — a field agent's registration (installations.field, phase 3):
 * works offline from the cached zones/services/offers, prints the receipts
 * from the phone's block, queues one sync item; the customer is "pending
 * activation" until the office activates it.
 */
type Mode = 'online' | 'field';

interface RegisteredReceipt {
    label: string;
    amount: number;
    issued: IssuedFieldReceipt | null;
}

function localToday(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

const LEVELS: Array<'normal' | 'Vip' | 'Operator'> = ['normal', 'Vip', 'Operator'];

/**
 * Laravel's standard 422 `{message, errors: {field: [...]}}` shape — same
 * ad-hoc, scoped-locally extraction edit-profile.tsx/adjust-arrears/[uuid].tsx
 * already use (no shared helper for this exists in src/api/client.ts; see
 * edit-profile.tsx's own doc comment for why that's deliberate).
 */
function extractFieldErrors(error: unknown): Record<string, string[]> | undefined {
    return (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
}

/**
 * `services`/`services.*.service_uuid`/`services.*.price`/etc. — Laravel
 * flattens array-item rule failures to dotted keys (StoreCustomerRequest's
 * `services.*` rules), so surface whichever one fired first under the
 * Services section rather than only handling the bare `services` key
 * (required/min:1) and silently dropping a per-row failure.
 */
function firstServicesError(fieldErrors: Record<string, string[]>): string | undefined {
    const key = Object.keys(fieldErrors).find((k) => k === 'services' || k.startsWith('services.'));

    return key ? fieldErrors[key][0] : undefined;
}

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
 * Office roles (customers.create) save online, as before. A field agent
 * (installations.field, installation phase 3) registers the customer even
 * offline: the phone generates the customer's id, prints a receipt for each
 * amount collected from its receipt block, and queues the registration;
 * the office activates it (or rejects it) once it syncs.
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
    const [mode, setMode] = useState<Mode>('online');
    const [registered, setRegistered] = useState<{ name: string; receipts: RegisteredReceipt[] } | null>(null);
    const mayCreateOnline = can('customers.create');
    const mayRegisterInField = can('installations.field');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [zones, setZones] = useState<ZoneApi[]>([]);
    const [catalogue, setCatalogue] = useState<ServiceCatalogueApi[]>([]);

    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [location, setLocation] = useState('');
    const [email, setEmail] = useState('');
    const [preferredChannel, setPreferredChannel] = useState<PreferredChannel>('whatsapp');
    const [level, setLevel] = useState<'normal' | 'Vip' | 'Operator'>('normal');
    const [zoneUuid, setZoneUuid] = useState<string | null>(null);
    const [services, setServices] = useState<CustomerServiceSelection[]>([]);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    // Installation & first payment (installation phase 2).
    const [installationOptions, setInstallationOptions] = useState<InstallationOptionsResponse['data'] | null>(null);
    const [installation, setInstallation] = useState<InstallationFormState | null>(null);
    const [duplicates, setDuplicates] = useState<InstallationPreviewApi['duplicates']>([]);
    const [notADuplicate, setNotADuplicate] = useState(false);

    function applyFormData(data: { zones: ZoneApi[]; catalogue: ServiceCatalogueApi[]; installationOptions: InstallationOptionsResponse['data'] | null }, fieldMode: boolean) {
        setZones(data.zones);
        setCatalogue(data.catalogue);

        // An agent always gets the section in the field; without cached
        // options (older server) it still records fee and months.
        const options =
            data.installationOptions ??
            (fieldMode ? { offers: [], max_free_months: 3, can_manage: false, can_take_payment: can('payments.create'), today: localToday() } : null);

        if (options) {
            setInstallationOptions(options);
            setInstallation(
                initialInstallationState(
                    fieldMode ? localToday() : options.today,
                    options.can_manage || fieldMode ? (options.offers.find((o) => o.is_default)?.uuid ?? null) : null,
                ),
            );
        }

        const defaultService = data.catalogue.find((s) => s.is_default);
        setServices(defaultService ? [{ service_uuid: defaultService.uuid, service_variant_uuid: null, price: defaultService.price }] : []);
    }

    /** A field agent's form: live data when online (and cached), else the cache. */
    function loadField() {
        setMode('field');
        setPhase('loading');
        setErrorMessage(null);

        const fromCache = () =>
            getCachedFormData().then((cached) => {
                if (!cached) {
                    setPhase('offline');
                    return;
                }

                applyFormData(cached, true);
            });

        const source = getSyncState().isOnline
            ? refreshFormData()
                  .then((data) => applyFormData(data, true))
                  .catch(() => fromCache())
            : fromCache();

        // An agent registers customers in their own zones only (the server
        // refuses others), so the picker offers just those once known — and
        // picks it when there is only one.
        void source
            .then(() => getMyZoneUuids())
            .then((mine) => {
                if (mine.length > 0) {
                    setZones((all) => {
                        const own = all.filter((zone) => mine.includes(zone.uuid));

                        return own.length > 0 ? own : all;
                    });
                }

                if (mine.length === 1) {
                    setZoneUuid((current) => current ?? mine[0]);
                }

                setPhase((current) => (current === 'loading' ? 'ready' : current));
            });
    }

    function load() {
        if (mayRegisterInField && !(mayCreateOnline && getSyncState().isOnline)) {
            loadField();
            return;
        }

        if (!getSyncState().isOnline) {
            setPhase('offline');
            return;
        }

        setMode('online');
        setPhase('loading');
        setErrorMessage(null);

        Promise.all([
            fetchZones(),
            fetchServiceCatalogue(),
            // An older server has no installation section — the form then
            // simply doesn't show one.
            fetchInstallationOptions().catch((error) => {
                if (isNetworkError(error)) {
                    throw error;
                }

                return null;
            }),
        ])
            .then(([zonesResponse, catalogueResponse, installationResponse]) => {
                setZones(zonesResponse.data);
                setCatalogue(catalogueResponse.data);
                // Kept for offline use (installation phase 3).
                void cacheFormData({ zones: zonesResponse.data, catalogue: catalogueResponse.data, installationOptions: installationResponse?.data ?? null });

                if (installationResponse) {
                    const options = installationResponse.data;
                    setInstallationOptions(options);
                    setInstallation(
                        initialInstallationState(options.today, options.can_manage ? (options.offers.find((o) => o.is_default)?.uuid ?? null) : null),
                    );
                }

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

    // Offline, the phone checks its own cached customers for a duplicate.
    useEffect(() => {
        if (mode !== 'field') {
            return;
        }

        const timer = setTimeout(() => {
            void findLocalDuplicates(name, phone).then((found) => {
                setDuplicates(found);
                if (found.length === 0) {
                    setNotADuplicate(false);
                }
            });
        }, 400);

        return () => clearTimeout(timer);
    }, [mode, name, phone]);

    async function submitField() {
        const request = toRequestFields(installation ?? initialInstallationState(localToday(), null));
        const nextErrors = fieldRegistrationErrors({
            name,
            phone,
            zoneUuid,
            services,
            fee: request.installation_fee,
            paidNow: request.installation_paid,
        });

        setErrors(nextErrors);

        if (Object.keys(nextErrors).length > 0) {
            return;
        }

        if (duplicates.length > 0 && !notADuplicate) {
            Alert.alert('Possible duplicate', 'This may be an existing customer. Check the list and confirm it is a different person.');

            return;
        }

        setPhase('submitting');

        try {
            // The customer's id is made here: the receipts are signed with it.
            const customerUuid = generateUuid();
            const paid = installationPaidCents(request.installation_paid);
            const upfront = upfrontCents(services, request.service_plans);

            const sign = async (cents: number): Promise<{ receipt: FieldInstallationReceipt; issued: IssuedFieldReceipt | null } | null> => {
                if (cents <= 0) {
                    return null;
                }

                const receiptUuid = generateUuid();
                const issued = await issueFieldReceipt(receiptUuid, customerUuid, cents / 100);

                return {
                    receipt: {
                        local_uuid: receiptUuid,
                        receipt_number: issued?.receipt_number ?? null,
                        security_code: issued?.security_code ?? null,
                        receipt_issued_at: issued?.receipt_issued_at ?? null,
                    },
                    issued,
                };
            };

            const installationReceipt = await sign(paid);
            const upfrontReceipt = await sign(upfront);

            const item = buildFieldInstallationItem({
                localUuid: generateUuid(),
                customerUuid,
                createdAt: new Date().toISOString(),
                zoneUuid: zoneUuid as string,
                name,
                phone,
                location,
                email,
                preferredChannel,
                level,
                services,
                request,
                installationReceipt: installationReceipt?.receipt ?? null,
                upfrontReceipt: upfrontReceipt?.receipt ?? null,
            });

            const bill = Object.values(servicePrices(services)).reduce((sum, cents) => sum + cents, 0) / 100;

            await insertLocalInstallation(item, (paid + upfront) / 100, bill);
            syncManager.notifyLocalWrite();

            setRegistered({
                name: item.name,
                receipts: [
                    { label: 'Installation', amount: paid / 100, issued: installationReceipt?.issued ?? null },
                    { label: 'Months paid upfront', amount: upfront / 100, issued: upfrontReceipt?.issued ?? null },
                ].filter((row) => row.amount > 0),
            });
            setPhase('registered');
        } catch (error) {
            setPhase('ready');
            Alert.alert('Could not save the registration', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    async function submit() {
        if (mode === 'field') {
            await submitField();

            return;
        }

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

        if (duplicates.length > 0 && !notADuplicate) {
            Alert.alert('Possible duplicate', 'This may be an existing customer. Check the list and confirm it is a different person.');

            return;
        }

        setPhase('submitting');

        try {
            const response = await createCustomer({
                ...(installation ? toRequestFields(installation) : {}),
                zone_uuid: zoneUuid as string,
                name: name.trim(),
                phone: phone.trim(),
                location: location.trim() || undefined,
                email: email.trim() || undefined,
                preferred_channel: preferredChannel,
                level,
                services,
            });

            // Best-effort — pulls the new customer into every device's cache
            // (including this one) sooner than the next natural sync tick.
            void syncManager.syncNow('manual');

            const receipts = (response.payments ?? []).map((p) => p.receipt_number).filter(Boolean);
            const coverage = response.data.coverage?.label;

            Alert.alert('Customer added', [`${name.trim()} has been added.`, coverage, receipts.length > 0 ? `Receipts: ${receipts.join(', ')}` : null].filter(Boolean).join('\n'), [
                { text: 'OK', onPress: () => router.back() },
            ]);
        } catch (error) {
            setPhase('ready');

            // Surface StoreCustomerRequest's actual per-field 422 reasons
            // inline (same helper this app already uses in edit-profile.tsx/
            // adjust-arrears/[uuid].tsx) rather than letting the fallback
            // Alert show Laravel's generic "The given data was invalid." —
            // that message alone gives the user nothing to fix.
            const fieldErrors = extractFieldErrors(error);

            if (fieldErrors) {
                setErrors((prev) => ({
                    ...prev,
                    name: fieldErrors.name?.[0],
                    phone: fieldErrors.phone?.[0],
                    zone_uuid: fieldErrors.zone_uuid?.[0],
                    location: fieldErrors.location?.[0],
                    email: fieldErrors.email?.[0],
                    services: firstServicesError(fieldErrors) ?? prev.services,
                    connected_on: fieldErrors.connected_on?.[0],
                    installation_offer_uuid: fieldErrors.installation_offer_uuid?.[0],
                    installation_fee: fieldErrors.installation_fee?.[0],
                    installation_paid: fieldErrors.installation_paid?.[0],
                    installation_reason: fieldErrors.installation_reason?.[0],
                    service_plans: Object.entries(fieldErrors).find(([key]) => key.startsWith('service_plans'))?.[1]?.[0],
                    method_reference: fieldErrors.method_reference?.[0],
                }));
            }

            Alert.alert('Could not add customer', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    if (!mayCreateOnline && !mayRegisterInField) {
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
                    subtitle={
                        mode === 'field'
                            ? 'Open this screen once while online so the phone keeps the zones, services and offers for offline registrations.'
                            : 'Adding a customer needs a live connection to check the zone and service list. Connect and this screen will pick up automatically.'
                    }
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

    if (phase === 'registered' && registered) {
        return (
            <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
                <Stack.Screen options={{ title: 'Customer registered' }} />
                <Card>
                    <Text style={styles.sectionTitle}>Pending activation</Text>
                    <Text style={styles.registeredName}>{registered.name}</Text>
                    <Text style={styles.registeredHint}>
                        Saved on this phone and sent with the next sync. The customer is not billed until the office activates them — you will see
                        &quot;Activated&quot; or the reason it was rejected under Sync status.
                    </Text>
                </Card>
                {registered.receipts.map((row) => (
                    <Card key={row.label}>
                        <Text style={styles.sectionTitle}>RECEIPT / REÇU · {row.label}</Text>
                        <Text style={styles.registeredName}>{row.amount.toLocaleString()} FCFA</Text>
                        {row.issued ? (
                            <>
                                <Text style={styles.registeredHint}>
                                    {row.issued.receipt_number} · {new Date(row.issued.receipt_issued_at).toLocaleString()}
                                </Text>
                                <Text style={styles.receiptCode}>{formatReceiptCode(row.issued.security_code)}</Text>
                                <Text style={styles.registeredHint} selectable>
                                    Check at {receiptCheckUrl(row.issued.check_url_base, row.issued.receipt_number, row.issued.security_code)}
                                </Text>
                            </>
                        ) : (
                            <Text style={styles.registeredHint}>No receipt numbers left on this phone — the office issues this receipt.</Text>
                        )}
                    </Card>
                ))}
                <Button title="Done" size="large" onPress={() => router.back()} />
            </ScrollView>
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
                <Text style={styles.sectionTitle}>Receipts</Text>
                <ReceiptDeliveryFields
                    email={email}
                    onEmailChange={(text) => {
                        setEmail(text);
                        setErrors((prev) => ({ ...prev, email: undefined }));
                    }}
                    channel={preferredChannel}
                    onChannelChange={setPreferredChannel}
                    emailError={errors.email}
                />
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
                <ServicesPicker
                    catalogue={catalogue}
                    value={services}
                    onChange={(next) => {
                        setServices(next);
                        // Matches every other field on this form: the error
                        // clears the moment the user acts on it, rather than
                        // sitting stale until the next submit attempt.
                        setErrors((prev) => ({ ...prev, services: undefined }));
                    }}
                    error={errors.services}
                />
            </Card>

            {installationOptions && installation && (
                <InstallationFields
                    options={installationOptions}
                    state={installation}
                    onChange={setInstallation}
                    services={services}
                    catalogue={catalogue}
                    name={name}
                    phone={phone}
                    errors={errors}
                    offline={mode === 'field'}
                    onPreview={(preview) => {
                        const found = preview?.duplicates ?? [];
                        setDuplicates(found);
                        if (found.length === 0) {
                            setNotADuplicate(false);
                        }
                    }}
                />
            )}

            <DuplicateWarning duplicates={duplicates} confirmed={notADuplicate} onConfirm={setNotADuplicate} />

            <Button
                title={submitting ? 'Adding…' : mode === 'field' ? 'Register customer' : 'Add Customer'}
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
    // minHeight/justifyContent: 'center' — mobile-app-react-native.md §6's
    // 48dp touch-target floor. paddingVertical: spacing.sm (8) alone left
    // this chip around ~36dp, the exact class of bug that section's own
    // dated audits found (and fixed the same way) on the Customers list's
    // filter chips and Log a Complaint's category chips.
    zoneChip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
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
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    levelChipSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    levelChipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary, textTransform: 'capitalize' },
    levelChipLabelSelected: { color: colors.textInverse },
    notAuthorizedCard: { margin: spacing.lg },
    registeredName: { fontSize: fontSize.lg, fontWeight: '700', color: colors.textPrimary },
    registeredHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
    receiptCode: { fontSize: fontSize.xl, fontWeight: '800', letterSpacing: 2, color: colors.textPrimary, marginTop: spacing.sm },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
