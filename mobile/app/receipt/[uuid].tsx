import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { fetchPaymentReceipt, fetchReceiptWhatsappMessage, openReceiptPdf, sendReceiptEmail } from '../../src/api/paymentReceipts';
import { buildWhatsAppBillLink } from '../../src/utils/whatsapp';
import { extractErrorMessage, isNetworkError } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';
import type { PaymentReceiptApi, ReceiptFormat } from '../../src/types/api';

const FORMATS: Array<{ value: ReceiptFormat; label: string }> = [
    { value: 'a6', label: 'A6 sheet' },
    { value: 'thermal58', label: '58 mm roll' },
    { value: 'thermal80', label: '80 mm roll' },
];

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
    const [sendingWhatsapp, setSendingWhatsapp] = useState(false);
    const [sendingEmail, setSendingEmail] = useState(false);
    const [format, setFormat] = useState<ReceiptFormat>('a6');
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

    /**
     * "Send via WhatsApp" — manual mode (bill-notifications.md §1): a wa.me
     * deep link the staff member opens to send from their OWN WhatsApp
     * session. The message + normalized phone are composed fresh server-side
     * (which also logs the send to sent_log); this only builds the link and
     * hands it to the OS. Online-only, same as "View PDF".
     */
    async function handleSendWhatsapp() {
        if (!uuid || sendingWhatsapp) {
            return;
        }

        setSendingWhatsapp(true);

        try {
            const { data } = await fetchReceiptWhatsappMessage(uuid);

            if (!data.available) {
                Alert.alert('Cannot send receipt', 'This customer has no valid WhatsApp number on file.');
                return;
            }

            const link = buildWhatsAppBillLink(data.phone, data.message);

            if (!link) {
                Alert.alert('Cannot send receipt', 'Could not prepare the WhatsApp message.');
                return;
            }

            await Linking.openURL(link);
        } catch (error) {
            Alert.alert('Could not prepare WhatsApp message', extractErrorMessage(error, "Couldn't reach the server."));
        } finally {
            setSendingWhatsapp(false);
        }
    }

    /**
     * "Email receipt" — queues the PDF to the customer's email address
     * server-side. Disabled (with the reason shown) when the customer has
     * no email or email is switched off for the company.
     */
    async function handleSendEmail() {
        if (!uuid || sendingEmail) {
            return;
        }

        setSendingEmail(true);

        try {
            const { message } = await sendReceiptEmail(uuid);
            Alert.alert('Receipt emailed', message);
        } catch (error) {
            Alert.alert('Could not email receipt', extractErrorMessage(error, "Couldn't reach the server."));
        } finally {
            setSendingEmail(false);
        }
    }

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
                {receipt.invoice_number ? <Text style={styles.issued}>For bill {receipt.invoice_number}</Text> : null}
            </Card>

            {isVoid ? (
                <Card>
                    <Text style={styles.voidNote}>
                        This receipt was voided because the payment was rejected. The PDF link no longer works.
                    </Text>
                </Card>
            ) : (
                <>
                    {/* A6 for a normal printer / sharing; 58 or 80 mm for a
                        Bluetooth pocket printer (the PDF is sized to the roll). */}
                    <View style={styles.formatRow}>
                        {FORMATS.map((option) => {
                            const selected = option.value === format;

                            return (
                                <Pressable
                                    key={option.value}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected }}
                                    onPress={() => setFormat(option.value)}
                                    style={[styles.formatChip, selected && styles.formatChipSelected]}
                                >
                                    <Text style={[styles.formatChipLabel, selected && styles.formatChipLabelSelected]}>{option.label}</Text>
                                </Pressable>
                            );
                        })}
                    </View>
                    <Button
                        title={format === 'a6' ? 'View / Print PDF' : 'Print on pocket printer'}
                        size="large"
                        onPress={() => void openReceiptPdf(receipt.shared_pdf_urls?.[format] ?? receipt.shared_pdf_url)}
                    />
                    <Button
                        title={sendingWhatsapp ? 'Preparing…' : 'Send via WhatsApp'}
                        loading={sendingWhatsapp}
                        disabled={sendingWhatsapp}
                        onPress={() => void handleSendWhatsapp()}
                        style={styles.whatsappButton}
                    />
                    <Button
                        title={sendingEmail ? 'Sending…' : receipt.customer_email ? `Email to ${receipt.customer_email}` : 'Email receipt'}
                        variant="secondary"
                        loading={sendingEmail}
                        disabled={sendingEmail || receipt.email_unavailable_reason !== null}
                        onPress={() => void handleSendEmail()}
                    />
                    {receipt.email_unavailable_reason ? <Text style={styles.hint}>{receipt.email_unavailable_reason}</Text> : null}
                </>
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
    hint: { fontSize: fontSize.xs, color: colors.textSecondary, textAlign: 'center' },
    formatRow: { flexDirection: 'row', gap: spacing.sm },
    formatChip: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: touchTarget.floor,
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    formatChipSelected: { backgroundColor: colors.accent.payment, borderColor: colors.accent.payment },
    formatChipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    formatChipLabelSelected: { color: colors.textInverse },
    // WhatsApp-brand treatment, same darker AAA-safe teal as Customer
    // Detail's "Send Bill via WhatsApp" — see colors.whatsapp's doc comment.
    whatsappButton: { backgroundColor: colors.whatsapp },
    blockedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    blockedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
