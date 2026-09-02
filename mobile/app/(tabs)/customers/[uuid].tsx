import { useCallback, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Card } from '../../../src/components/ui/Card';
import { Button } from '../../../src/components/ui/Button';
import { Badge, type BadgeTone } from '../../../src/components/ui/Badge';
import { EmptyState } from '../../../src/components/ui/EmptyState';
import { getCustomerByUuid } from '../../../src/db/customers';
import { getLastPaymentForCustomer } from '../../../src/db/payments';
import { fetchBillWhatsappMessage } from '../../../src/api/bills';
import { extractErrorMessage } from '../../../src/api/client';
import { colors } from '../../../src/theme/colors';
import { fontSize, spacing } from '../../../src/theme/tokens';
import { formatFcfa } from '../../../src/utils/format';
import { buildWhatsAppBillLink } from '../../../src/utils/whatsapp';
import type { LocalCustomer, LocalPayment } from '../../../src/types/db';

const STATUS_BADGE: Record<string, { label: string; tone: BadgeTone }> = {
    active: { label: 'Active', tone: 'verified' },
    passive: { label: 'Passive', tone: 'neutral' },
    disconnected: { label: 'Disconnected', tone: 'rejected' },
    suspended: { label: 'Suspended', tone: 'pending' },
};

const PAYMENT_SYNC_BADGE: Record<LocalPayment['sync_status'], { label: string; tone: BadgeTone }> = {
    queued: { label: 'Saved · will sync', tone: 'offline' },
    syncing: { label: 'Syncing…', tone: 'syncing' },
    synced: { label: 'Synced', tone: 'synced' },
    failed: { label: 'Sync failed', tone: 'error' },
};

function formatDate(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * Customer Detail — reads entirely from the local SQLite cache (customers +
 * this device's own payments table), same offline-first principle as the
 * Customers list: no live API call on this screen. `total_arrears`/`credit`
 * now come down through pull() (see SyncService::upsertedCustomers()), so
 * this renders correctly offline. The Reconnect & Pay flow (app/reconnect/
 * [uuid].tsx) is the one screen that DOES call the live API, because it's
 * inherently an online-only server action — see that screen's doc comment.
 */
export default function CustomerDetailScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();

    const [customer, setCustomer] = useState<LocalCustomer | null | undefined>(undefined);
    const [lastPayment, setLastPayment] = useState<LocalPayment | null>(null);
    const [sendingWhatsapp, setSendingWhatsapp] = useState(false);

    useFocusEffect(
        useCallback(() => {
            if (!uuid) {
                return;
            }

            void getCustomerByUuid(uuid).then(setCustomer);
            void getLastPaymentForCustomer(uuid).then(setLastPayment);
        }, [uuid]),
    );

    if (customer === undefined) {
        return <View style={styles.flex} />;
    }

    if (customer === null) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Customer' }} />
                <EmptyState
                    title="Customer not found in this device's cache"
                    subtitle="It may belong to a different zone, or hasn't synced to this device yet."
                />
            </View>
        );
    }

    const arrears = customer.total_arrears ?? 0;
    const credit = customer.credit ?? 0;
    const statusBadge = STATUS_BADGE[customer.status] ?? { label: customer.status, tone: 'neutral' as BadgeTone };
    const needsReconnect = customer.status === 'disconnected' || customer.status === 'suspended';
    // 2026-08 mobile field-ops widening: an agent can disconnect a
    // non-paying customer directly from the field (see app/disconnect/
    // [uuid].tsx's doc comment) — surfaced here as a secondary action next
    // to Record Payment, same "active/passive only" gate as the web
    // CustomerStatusActions component's canDisconnectOrSuspend. Real
    // enforcement is server-side (App\Policies\CustomerPolicy::
    // disconnect(), zone-scoped for agent); this button just avoids
    // offering an action that's obviously irrelevant once already
    // disconnected/suspended.
    const canDisconnect = customer.status === 'active' || customer.status === 'passive';

    function handleCall() {
        if (customer?.phone) {
            void Linking.openURL(`tel:${customer.phone}`);
        }
    }

    function handlePrimaryAction() {
        if (needsReconnect) {
            router.push(`/reconnect/${uuid}`);
        } else {
            router.push(`/(tabs)/record-payment?customerUuid=${uuid}`);
        }
    }

    /**
     * "Send Bill via WhatsApp" — manual mode only (bill-notifications.md
     * §1): a wa.me deep link the agent opens to send from their OWN
     * WhatsApp session. One customer at a time, agent-initiated — not a
     * bulk/broadcast send (that's a separate, Twilio-based, landlord-gated
     * feature, not this action). Always requires connectivity, like
     * reconnect's fetchCustomerDetail() call, since the message is composed
     * fresh server-side from the customer's real current manuscript — not
     * something read from the local SQLite cache.
     */
    async function handleSendBillWhatsapp() {
        if (!uuid || sendingWhatsapp) {
            return;
        }

        setSendingWhatsapp(true);

        try {
            const response = await fetchBillWhatsappMessage(uuid);
            const { available, reason, phone, message } = response.data;

            if (!available) {
                Alert.alert(
                    'Cannot send bill',
                    reason === 'no_manuscript'
                        ? `${customer?.name ?? 'This customer'} has no bill calculated yet — nothing to send.`
                        : `${customer?.name ?? 'This customer'} has no valid WhatsApp number on file.`,
                );
                return;
            }

            const link = buildWhatsAppBillLink(phone, message);

            if (!link) {
                Alert.alert('Cannot send bill', 'Could not prepare the WhatsApp message.');
                return;
            }

            await Linking.openURL(link);
        } catch (error) {
            Alert.alert('Could not prepare WhatsApp message', extractErrorMessage(error, "Couldn't reach the server."));
        } finally {
            setSendingWhatsapp(false);
        }
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: customer.name }} />

            <View style={styles.headerRow}>
                <Text style={styles.name}>{customer.name}</Text>
                <Badge label={statusBadge.label} tone={statusBadge.tone} />
            </View>

            {customer.phone ? (
                <Card onPress={handleCall} accentColor={colors.accent.home}>
                    <Text style={styles.phoneLabel}>Phone</Text>
                    <Text style={styles.phoneValue}>{customer.phone}</Text>
                    <Text style={styles.phoneHint}>Tap to call</Text>
                </Card>
            ) : (
                <Card>
                    <Text style={styles.phoneLabel}>Phone</Text>
                    <Text style={styles.phoneMissing}>No phone on file</Text>
                </Card>
            )}

            <View style={styles.statGrid}>
                <Card style={styles.statCard}>
                    <Text style={styles.statLabel}>Bill</Text>
                    <Text style={styles.statValue}>{formatFcfa(customer.bill)}</Text>
                </Card>
                <Card style={styles.statCard} accentColor={arrears > 0 ? colors.danger : undefined}>
                    <Text style={styles.statLabel}>Arrears</Text>
                    <Text style={[styles.statValue, arrears > 0 && styles.statValueDanger]}>
                        {formatFcfa(arrears)}
                    </Text>
                </Card>
                <Card style={styles.statCard} accentColor={credit > 0 ? colors.accent.payment : undefined}>
                    <Text style={styles.statLabel}>Credit</Text>
                    <Text style={[styles.statValue, credit > 0 && styles.statValuePositive]}>
                        {formatFcfa(credit)}
                    </Text>
                </Card>
            </View>

            <Card>
                <Text style={styles.sectionTitle}>Last payment</Text>
                {lastPayment ? (
                    <>
                        <View style={styles.lastPaymentRow}>
                            <View>
                                <Text style={styles.lastPaymentAmount}>{formatFcfa(lastPayment.amount)}</Text>
                                <Text style={styles.lastPaymentDate}>{formatDate(lastPayment.created_at)}</Text>
                            </View>
                            <Badge
                                label={PAYMENT_SYNC_BADGE[lastPayment.sync_status].label}
                                tone={PAYMENT_SYNC_BADGE[lastPayment.sync_status].tone}
                            />
                        </View>
                        {/*
                          A receipt only exists once a payment has synced (so a
                          real server_uuid exists to address it) AND been
                          verified by the office — the same choke point that
                          auto-issues it (payment-receipts-and-whatsapp.md). The
                          receipt screen itself re-checks and shows a friendly
                          "not issued yet" state if it 404s.
                        */}
                        {lastPayment.server_uuid && lastPayment.verification_status === 'verified' ? (
                            <Pressable
                                accessibilityRole="button"
                                onPress={() => router.push(`/receipt/${lastPayment.server_uuid}`)}
                                style={styles.receiptLink}
                            >
                                <Text style={styles.receiptLinkText}>View receipt</Text>
                            </Pressable>
                        ) : null}
                    </>
                ) : (
                    <Text style={styles.noPayment}>No payment recorded from this device yet.</Text>
                )}
            </Card>

            <Button
                title={needsReconnect ? 'Reconnect & Pay' : 'Record Payment'}
                size="large"
                onPress={handlePrimaryAction}
                variant={needsReconnect ? 'secondary' : 'primary'}
            />

            {/*
              2026-08-27: Disconnect and Send-Bill regrouped under a labeled
              "Other actions" cluster, visually separated from the primary
              Record Payment CTA above. Field-service UX research (thumb-
              zone/critical-action placement) is consistent on this point:
              destructive actions shouldn't carry the same visual weight as
              the primary action, and should sit with clear separation from
              it rather than directly beneath it — the previous layout had
              Disconnect as a full 56dp solid-red button stacked immediately
              under Record Payment, i.e. identical prominence to the
              highest-frequency action on the screen, for what is in fact
              the rarest one. Disconnect itself still navigates to a
              confirmation screen (app/disconnect/[uuid].tsx, stage 2's
              scope, not touched here) — nothing here changes what actually
              happens, only how strongly this entry point competes for
              attention against Record Payment. See Button's 'dangerOutline'
              variant for the lower-emphasis treatment.

              2026-08-28: "Adjust Arrears" added to this same cluster — the
              mobile REQUEST side of the maker-checker write-off workflow
              (arrears-adjustment.md). Unlike Disconnect/WhatsApp above, it
              is never conditionally hidden: ArrearsAdjustmentPolicy::create()
              is ungated for every role and every customer status (a
              disconnected customer's frozen, wrong arrears figure is
              exactly this feature's central use case — see that doc's
              section 4), so there's no "canAdjustArrears" gate to mirror
              here, matching how the web modal renders unconditionally on
              Customers/Show.tsx too.
            */}
            <View style={styles.secondaryActions}>
                <Text style={styles.secondaryActionsLabel}>Other actions</Text>

                {customer.phone ? (
                    <Button
                        title={sendingWhatsapp ? 'Preparing…' : 'Send Bill via WhatsApp'}
                        loading={sendingWhatsapp}
                        disabled={sendingWhatsapp}
                        onPress={handleSendBillWhatsapp}
                        style={styles.whatsappButton}
                    />
                ) : null}

                <Button
                    title="Adjust Arrears / Credit"
                    variant="secondary"
                    onPress={() => router.push(`/adjust-arrears/${uuid}`)}
                    style={styles.arrearsButton}
                />

                {canDisconnect ? (
                    <Button
                        title="Disconnect this customer"
                        variant="dangerOutline"
                        onPress={() => router.push(`/disconnect/${uuid}`)}
                    />
                ) : null}
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    name: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary, flexShrink: 1 },
    phoneLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    phoneValue: { fontSize: fontSize.lg, fontWeight: '700', color: colors.accent.home, marginTop: spacing.xs },
    phoneHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    phoneMissing: { fontSize: fontSize.md, color: colors.textSecondary, marginTop: spacing.xs },
    statGrid: { flexDirection: 'row', gap: spacing.sm },
    statCard: { flex: 1 },
    statLabel: { fontSize: fontSize.xs, fontWeight: '600', color: colors.textSecondary },
    statValue: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.xs },
    statValueDanger: { color: colors.danger },
    statValuePositive: { color: colors.accent.payment },
    sectionTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginBottom: spacing.sm },
    lastPaymentRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    lastPaymentAmount: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    lastPaymentDate: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    receiptLink: { marginTop: spacing.md, alignSelf: 'flex-start' },
    receiptLinkText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.payment },
    noPayment: { fontSize: fontSize.sm, color: colors.textSecondary },
    // Groups WhatsApp + Disconnect under one labeled, visually-separated
    // cluster (marginTop, not just the screen's default content gap) so it
    // reads as clearly secondary to the Record Payment button above it —
    // see the doc comment above this section's JSX.
    secondaryActions: { marginTop: spacing.sm, gap: spacing.sm },
    secondaryActionsLabel: {
        fontSize: fontSize.xs,
        fontWeight: '700',
        color: colors.textSecondary,
        textTransform: 'uppercase',
        letterSpacing: 0.3,
    },
    // Distinct WhatsApp-brand treatment, not a payment or status action —
    // see colors.whatsapp's doc comment for why it's the darker AAA-safe
    // teal rather than WhatsApp's brighter brand green.
    whatsappButton: { backgroundColor: colors.whatsapp },
    // 'secondary' variant (light fill, dark text) with a violet border laid
    // on top — ties this action to the new colors.accent.arrears identity
    // without needing a whole new Button variant just for one screen's
    // entry point.
    arrearsButton: { borderColor: colors.accent.arrears },
});
