import { useCallback, useRef, useState } from 'react';
import { FlatList, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { fetchEligibleForDisconnection } from '../src/api/customers';
import { isNetworkError, extractErrorMessage } from '../src/api/client';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';
import { formatFcfa } from '../src/utils/format';
import type { EligibleCustomerApi } from '../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready';

/**
 * Disconnections — the arrears-based "flagged for non-payment" eligibility
 * list, zone-scoped to the agent. NOT the office bulk-action workboard
 * (App\Policies\CustomerPolicy::viewStatusBoard()/bulkDisconnect() stay
 * super/admin/manager-only — no bulk selection here). This is the actual
 * field workflow: an agent walks their zone and this screen tells them
 * which of their own customers have crossed the arrears threshold
 * (App\Services\CustomerEligibilityService::THRESHOLD_MULTIPLIER — 3x the
 * customer's monthly bill, only once past the payment deadline) so they can
 * go act on it. Tapping a row goes straight into the existing
 * app/disconnect/[uuid].tsx action screen.
 *
 * Maps onto GET /api/v1/customers/eligible-for-disconnection
 * (App\Http\Controllers\Api\CustomerController::eligibleForDisconnection()),
 * gated by CustomerPolicy::viewEligibilityBoard() (admits super/admin/
 * manager/agent) and force-scoped server-side to the caller's own zone for
 * the `agent` role — this screen sends no zone_uuid at all, since there is
 * nothing for it to usefully choose: the server ignores it for an agent
 * regardless. See fetchEligibleForDisconnection()'s doc comment.
 *
 * Online-only, same reasoning and phase-machine shape as
 * app/reconnect/[uuid].tsx / app/disconnect/[uuid].tsx: this is a live,
 * computed-at-request-time board (App\Services\CustomerEligibilityService's
 * own doc comment — deliberately not a persisted flag), not offline-
 * cacheable data, so there's no local SQLite table for it and no sync-queue
 * involvement. Retries automatically once connectivity returns, via the
 * same subscribeSyncState pattern those two screens use.
 *
 * Registration into a tab bar / nav entry point is a separate step (not
 * done here, per this build's cross-agent convention) — the route is
 * simply `/disconnections`.
 */
export default function DisconnectionsScreen() {
    const router = useRouter();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [customers, setCustomers] = useState<EligibleCustomerApi[]>([]);
    const phaseRef = useRef<Phase>('loading');

    const load = useCallback(() => {
        if (!getSyncState().isOnline) {
            phaseRef.current = 'offline';
            setPhase('offline');
            return;
        }

        phaseRef.current = 'loading';
        setPhase('loading');
        setErrorMessage(null);

        fetchEligibleForDisconnection()
            .then((response) => {
                setCustomers(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load the eligibility list."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, []);

    // Same "retry automatically once connectivity returns" behavior as
    // Reconnect & Pay / Disconnect — see those screens' doc comments.
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

    function handleCall(phone: string) {
        void Linking.openURL(`tel:${phone}`);
    }

    function renderItem({ item }: { item: EligibleCustomerApi }) {
        const arrears = Number(item.total_arrears);

        return (
            <Card onPress={() => router.push(`/disconnect/${item.uuid}`)} style={styles.row}>
                <View style={styles.rowTop}>
                    <Text style={styles.name} numberOfLines={1}>
                        {item.name}
                    </Text>
                    <Text style={styles.arrears}>{formatFcfa(arrears)}</Text>
                </View>
                {item.phone ? (
                    <Pressable onPress={() => handleCall(item.phone as string)} hitSlop={8}>
                        <Text style={styles.phone}>{item.phone}</Text>
                    </Pressable>
                ) : (
                    <Text style={styles.phoneMissing}>No phone on file</Text>
                )}
                <Text style={styles.ratio}>{item.arrears_ratio.toFixed(1)}x monthly bill</Text>
            </Card>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Disconnections', headerShown: true }} />
                <EmptyState title="Loading…" />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Disconnections', headerShown: true }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="This list is computed live from the server and can't be cached offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Disconnections', headerShown: true }} />
                <EmptyState title="Couldn't load this list" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <Stack.Screen options={{ title: 'Disconnections', headerShown: true }} />
            <View style={styles.header}>
                <Text style={styles.headerTitle}>Flagged for non-payment</Text>
                <Text style={styles.headerSubtitle}>
                    {customers.length === 0
                        ? 'No customers in your zone have crossed the arrears threshold.'
                        : `${customers.length} customer${customers.length === 1 ? '' : 's'} in your zone ${
                              customers.length === 1 ? 'has' : 'have'
                          } crossed the arrears threshold.`}
                </Text>
            </View>

            {customers.length === 0 ? (
                <EmptyState
                    title="Nothing flagged right now"
                    subtitle="Come back after the next billing cycle, or once a customer's arrears grow — this list updates live."
                />
            ) : (
                <FlatList
                    data={customers}
                    keyExtractor={(c) => c.uuid}
                    renderItem={renderItem}
                    contentContainerStyle={styles.listContent}
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    header: { padding: spacing.lg, paddingBottom: spacing.md, gap: spacing.xs },
    headerTitle: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    headerSubtitle: { fontSize: fontSize.sm, color: colors.textSecondary },
    listContent: { padding: spacing.lg, paddingTop: 0, gap: spacing.sm },
    row: { gap: spacing.xs },
    rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    name: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    arrears: { fontSize: fontSize.md, fontWeight: '800', color: colors.danger },
    phone: { fontSize: fontSize.sm, color: colors.accent.home, fontWeight: '600' },
    phoneMissing: { fontSize: fontSize.sm, color: colors.textSecondary },
    ratio: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
});
