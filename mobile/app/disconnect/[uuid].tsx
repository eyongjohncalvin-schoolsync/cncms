import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TextInput as RNTextInput, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { fetchCustomerDetail, disconnectCustomer } from '../../src/api/customers';
import { isNetworkError, extractErrorMessage } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { syncManager } from '../../src/sync/SyncManager';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';
import type { CustomerDetailApi } from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting' | 'success';

/**
 * Disconnect — the field-triggered counterpart to Reconnect & Pay
 * (app/reconnect/[uuid].tsx), which this deliberately mirrors in shape
 * (online-only status transition, same phase machine) but not in
 * complexity: no fine/arrears collection, since disconnecting doesn't move
 * money. Maps onto PATCH /api/v1/customers/{uuid}/disconnect
 * (App\Http\Requests\DisconnectCustomerRequest ->
 * App\Services\CustomerStatusService::disconnect()).
 *
 * 2026-08 mobile field-ops widening: App\Policies\CustomerPolicy::
 * disconnect() now admits an `agent`, scoped to their own zone
 * (App\Support\TenantContext::zoneId) — the ONE status action an agent can
 * execute directly from the field, unlike Reconnect & Pay (still
 * super/admin/manager-only, since it also collects money) or a future
 * Suspend screen (not built — suspend's reasons are non-payment-unrelated
 * and carry an admin-only prepaid-pause choice, neither a field decision).
 * Since the mobile Customers list is already scoped to the agent's own
 * zone (mobile-app-react-native.md §4), every customer an agent can reach
 * this screen for is already in their zone in the normal case; the real
 * enforcement is server-side regardless, so a stale/mismatched local cache
 * just surfaces as a 403 handled like any other error below, never
 * silently allowed.
 *
 * Deliberately does NOT go through the offline /sync/push queue — same
 * reasoning as reconnect: it's a status transition guarded by the
 * customer's CURRENT server-side status (guardTransition() in
 * CustomerStatusService), which an offline queue could race against a
 * change made elsewhere, and it has no local_uuid idempotency support.
 *
 * Success confirmation is an in-screen state (phase='success'), not a
 * native `Alert.alert` — mirrors reconnect/[uuid].tsx's identical stage-2
 * fix and reasoning: an immediate, confirmed-on-server result gets the
 * GREEN "confirmed" badge language §5 reserves for that case, in the same
 * dedicated-confirmation-view shape the rest of the app's write flows
 * already use for their own success states. The pre-action "Confirm
 * disconnection" Alert stays a native dialog — it's the point-of-no-return
 * gate, a different UX role than signaling success.
 */
export default function DisconnectScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();
    const { role, can } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [customer, setCustomer] = useState<CustomerDetailApi | null>(null);
    const [note, setNote] = useState('');
    const phaseRef = useRef<Phase>('loading');

    const load = useCallback(() => {
        if (!uuid) {
            return;
        }

        if (!getSyncState().isOnline) {
            phaseRef.current = 'offline';
            setPhase('offline');
            return;
        }

        phaseRef.current = 'loading';
        setPhase('loading');
        setErrorMessage(null);

        fetchCustomerDetail(uuid)
            .then((response) => {
                setCustomer(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load this customer."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, [uuid]);

    // Same "retry automatically once connectivity returns" behavior as
    // Reconnect & Pay — see that screen's doc comment for why.
    useFocusEffect(
        useCallback(() => {
            load();

            return subscribeSyncState(() => {
                if (phaseRef.current === 'offline' && getSyncState().isOnline) {
                    load();
                }
            });
        }, [load]),
    );

    function handleConfirm() {
        if (!customer || !uuid) {
            return;
        }

        Alert.alert(
            'Confirm disconnection',
            `Disconnect ${customer.name}'s service for non-payment?`,
            [
                { text: 'Cancel', style: 'cancel' },
                { text: 'Disconnect', style: 'destructive', onPress: () => void submit() },
            ],
        );
    }

    async function submit() {
        if (!uuid || !customer) {
            return;
        }

        phaseRef.current = 'submitting';
        setPhase('submitting');

        try {
            await disconnectCustomer(uuid, { note: note.trim() || undefined });

            // Best-effort — pulls the updated status into the local cache
            // sooner than the next natural sync trigger, same as reconnect.
            void syncManager.syncNow('manual');

            phaseRef.current = 'success';
            setPhase('success');
        } catch (error) {
            phaseRef.current = 'ready';
            setPhase('ready');
            Alert.alert('Could not disconnect', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Disconnect' }} />
                <ActivityIndicator size="large" color={colors.danger} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Disconnect' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Disconnecting a customer changes their billing status on the server and can't be queued offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error' || !customer) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Disconnect' }} />
                <EmptyState title="Couldn't load this customer" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    if (phase === 'success') {
        return (
            <View style={styles.confirmFlex}>
                <Stack.Screen options={{ title: 'Disconnect' }} />
                <Badge label="Disconnected" tone="synced" />
                <Text style={styles.confirmTitle}>{customer.name}</Text>
                <Text style={styles.confirmBody}>Service disconnected for non-payment.</Text>
                <Text style={styles.confirmHint}>Confirmed on the server just now.</Text>
                <Button title="Done" size="large" onPress={() => router.back()} style={styles.confirmButton} />
            </View>
        );
    }

    // RBAC v2 Wave 4: `customers.change_status` is the matrix permission for
    // disconnect/suspend/reconnect (CustomerPolicy). Agents are NOT seeded
    // it — they get a zone-scoped disconnect via an OR-branch in
    // CustomerPolicy::disconnect() that a flat permission list can't see —
    // so `role === 'agent'` is allowed here too as a UI affordance, exactly
    // mirroring that policy. The backend still enforces the zone match.
    const authorized = can('customers.change_status') || role === 'agent';
    const submitting = phase === 'submitting';
    const alreadyDisconnected = customer.status === 'disconnected' || customer.status === 'suspended';

    const paymentExpiration = customer.manuscript?.payment_expiration ?? null;
    const prepaidDaysRemaining = paymentExpiration && new Date(paymentExpiration).getTime() > Date.now()
        ? Math.ceil((new Date(paymentExpiration).getTime() - Date.now()) / (24 * 60 * 60 * 1000))
        : null;

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Disconnect' }} />

            <Card>
                <Text style={styles.customerName}>{customer.name}</Text>
                <Text style={styles.customerStatus}>Currently {customer.status}</Text>
                {customer.zone_name ? <Text style={styles.customerZone}>{customer.zone_name}</Text> : null}
            </Card>

            {alreadyDisconnected ? (
                <Card accentColor={colors.status.offlineDot}>
                    <Text style={styles.notAuthorizedTitle}>Already {customer.status}</Text>
                    <Text style={styles.notAuthorizedBody}>
                        This customer isn&apos;t currently active — there&apos;s nothing to disconnect.
                    </Text>
                </Card>
            ) : (
                <>
                    <Card>
                        <View style={styles.lineRow}>
                            <Text style={styles.lineLabel}>Outstanding arrears</Text>
                            <Text style={styles.lineValue}>{formatFcfa(Number(customer.manuscript?.total_arrears ?? 0))}</Text>
                        </View>
                        <Text style={styles.readOnlyHint}>Reference only — arrears stay frozen once disconnected.</Text>
                    </Card>

                    {prepaidDaysRemaining !== null && (
                        <Card accentColor={colors.accent.customers}>
                            <Text style={styles.prepaidNote}>
                                This customer has <Text style={styles.prepaidNoteStrong}>{prepaidDaysRemaining}</Text> day
                                {prepaidDaysRemaining === 1 ? '' : 's'} of prepaid service remaining — it will be preserved and resumed
                                automatically once reconnected.
                            </Text>
                        </Card>
                    )}

                    <Card>
                        <Text style={styles.sectionTitle}>Note (optional)</Text>
                        <RNTextInput
                            value={note}
                            onChangeText={setNote}
                            placeholder="e.g. visited in field, no payment made"
                            placeholderTextColor={colors.textSecondary}
                            multiline
                            numberOfLines={3}
                            style={styles.noteInput}
                        />
                    </Card>

                    {authorized ? (
                        <Button
                            title={submitting ? 'Disconnecting…' : 'Confirm Disconnection'}
                            size="large"
                            variant="danger"
                            loading={submitting}
                            disabled={submitting}
                            onPress={handleConfirm}
                        />
                    ) : (
                        <Card accentColor={colors.status.offlineDot}>
                            <Text style={styles.notAuthorizedTitle}>Not authorized</Text>
                            <Text style={styles.notAuthorizedBody}>
                                Your account can&apos;t disconnect customers. Contact your office if this customer needs to be cut for
                                non-payment.
                            </Text>
                        </Card>
                    )}
                </>
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
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
    confirmButton: { width: '100%' },
    customerName: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    customerStatus: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs, textTransform: 'capitalize' },
    customerZone: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    lineRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: spacing.xs },
    lineLabel: { fontSize: fontSize.md, color: colors.textSecondary },
    lineValue: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    readOnlyHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.sm },
    sectionTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginBottom: spacing.sm },
    noteInput: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: 8,
        padding: spacing.sm,
        fontSize: fontSize.md,
        color: colors.textPrimary,
        textAlignVertical: 'top',
        minHeight: 72,
    },
    prepaidNote: { fontSize: fontSize.sm, color: colors.textSecondary },
    prepaidNoteStrong: { fontWeight: '800', color: colors.textPrimary },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
