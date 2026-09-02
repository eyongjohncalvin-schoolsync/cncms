import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { fetchPaymentReceipt, openReceiptPdf } from '../../src/api/paymentReceipts';
import { extractErrorMessage, isNetworkError } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';
import type { PaymentReceiptApi } from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'missing' | 'ready';

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * Payment Receipt — read-only view of the business-issued receipt for one
 * verified payment (Wave 2 of payment-receipts-and-whatsapp.md). Reached by
 * the payment's server uuid, e.g. from Customer Detail's "Last payment" card
 * once that payment has synced and been verified.
 *
 * ONLINE-ONLY, same reasoning as app/manuscript.tsx / app/adjust-arrears:
 * the receipt is never part of the offline `payments` outbox cache, and the
 * signed PDF link it hands back is minted fresh server-side. Retries
 * automatically once connectivity returns.
 *
 * READ-ONLY, ON PURPOSE — no "issue receipt" action anywhere here. Issuing /
 * re-issuing a receipt is web-only (PaymentReceiptPolicy::issue,
 * `payments.issue_receipt`), matching this app's "mobile views, web
 * issues/verifies" split. "View PDF" hands the signed public URL to the OS
 * browser (this project's RN app has no PDF viewer — see
 * src/api/paymentReceipts.ts).
 */
export default function ReceiptScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const { can, status: authStatus } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [receipt, setReceipt] = useState<PaymentReceiptApi | null>(null);
    const phaseRef = useRef<Phase>('loading');

    // RBAC v2: PaymentReceiptPolicy::view → `payments.view`.
    const authorized = can('payments.view');

    const load = useCallback(() => {
        if (!uuid || !authorized) {
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

        fetchPaymentReceipt(uuid)
            .then((response) => {
                setReceipt(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else if ((error as { response?: { status?: number } })?.response?.status === 404) {
                    phaseRef.current = 'missing';
                    setPhase('missing');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load this receipt."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, [uuid, authorized]);

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

    if (authStatus === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <ActivityIndicator size="large" color={colors.accent.payment} />
            </View>
        );
    }

    if (!authorized) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <View style={styles.content}>
                    <Card accentColor={colors.status.offlineDot}>
                        <Text style={styles.blockedTitle}>Receipts aren't available for your role</Text>
                        <Text style={styles.blockedBody}>Contact your office if you believe this is a mistake.</Text>
                    </Card>
                </View>
            </View>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <ActivityIndicator size="large" color={colors.accent.payment} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="The receipt is fetched live from the server and isn't cached offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'missing') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <EmptyState
                    title="No receipt for this payment yet"
                    subtitle="A receipt is issued automatically once the payment is verified by the office."
                />
            </View>
        );
    }

    if (phase === 'error' || !receipt) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Receipt' }} />
                <EmptyState title="Couldn't load this receipt" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    const isVoid = receipt.status === 'void';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Receipt' }} />

            <Card accentColor={isVoid ? colors.danger : colors.accent.payment}>
                <View style={styles.headerRow}>
                    <Text style={styles.number}>{receipt.receipt_number}</Text>
                    <Badge label={isVoid ? 'Void' : 'Issued'} tone={isVoid ? 'rejected' : 'verified'} />
                </View>
                <Text style={styles.amount}>{formatFcfa(Number(receipt.amount))}</Text>
                <Text style={styles.issued}>Issued {formatDateTime(receipt.issued_at)}</Text>
            </Card>

            {isVoid ? (
                <Card>
                    <Text style={styles.voidNote}>
                        This receipt was voided because the payment was rejected. The PDF link no longer works.
                    </Text>
                </Card>
            ) : (
                <Button title="View PDF" size="large" onPress={() => void openReceiptPdf(receipt.shared_pdf_url)} />
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    number: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary, flexShrink: 1 },
    amount: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.sm },
    issued: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
    voidNote: { fontSize: fontSize.sm, color: colors.textSecondary },
    blockedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    blockedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
