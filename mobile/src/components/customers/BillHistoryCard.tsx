import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../ui/Card';
import { Badge, type BadgeTone } from '../ui/Badge';
import { fetchBillHistory } from '../../api/bills';
import { extractErrorMessage, isNetworkError } from '../../api/client';
import { getSyncState, subscribeSyncState } from '../../sync/syncStore';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import { formatFcfa } from '../../utils/format';
import type { BillHistoryResponse, BillStatus } from '../../types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready';

const STATUS_BADGE: Record<BillStatus, { label: string; tone: BadgeTone }> = {
    paid: { label: 'Paid', tone: 'verified' },
    partly_paid: { label: 'Part paid', tone: 'pending' },
    unpaid: { label: 'Unpaid', tone: 'rejected' },
    nothing_due: { label: 'Nothing due', tone: 'neutral' },
};

function monthLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);
    const date = new Date(year, (month ?? 1) - 1, 1);

    return Number.isNaN(date.getTime()) ? period : date.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}

/**
 * "Bills & statement" on Customer Detail — the customer's recent monthly
 * bills with their paid status, and statement-of-account PDFs (6 / 12 / 24
 * months) opened in the device browser through signed links. Online-only
 * (the rest of Customer Detail reads the offline cache): shows a short
 * offline note instead and reloads by itself once connectivity returns.
 */
export function BillHistoryCard({ customerUuid }: { customerUuid: string }) {
    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [history, setHistory] = useState<BillHistoryResponse | null>(null);
    const phaseRef = useRef<Phase>('loading');

    const setBoth = (next: Phase) => {
        phaseRef.current = next;
        setPhase(next);
    };

    const load = useCallback(() => {
        if (!getSyncState().isOnline) {
            setBoth('offline');
            return;
        }

        setBoth('loading');

        fetchBillHistory(customerUuid)
            .then((response) => {
                setHistory(response);
                setBoth('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    setBoth('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load bills."));
                    setBoth('error');
                }
            });
    }, [customerUuid]);

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

    return (
        <Card>
            <Text style={styles.sectionTitle}>Bills &amp; statement</Text>

            {phase === 'loading' ? <ActivityIndicator color={colors.accent.customers} /> : null}
            {phase === 'offline' ? <Text style={styles.muted}>Connect to the internet to see bills and statements.</Text> : null}
            {phase === 'error' ? (
                <Pressable accessibilityRole="button" onPress={load}>
                    <Text style={styles.muted}>{errorMessage} Tap to retry.</Text>
                </Pressable>
            ) : null}

            {phase === 'ready' && history ? (
                <>
                    {history.data.length === 0 ? (
                        <Text style={styles.muted}>No bills yet.</Text>
                    ) : (
                        history.data.slice(0, 6).map((bill) => {
                            const badge = STATUS_BADGE[bill.status];

                            return (
                                <View key={bill.period} style={styles.billRow}>
                                    <View style={styles.billInfo}>
                                        <Text style={styles.billMonth}>{monthLabel(bill.period)}</Text>
                                        <Text style={styles.billNumber}>{bill.number}</Text>
                                    </View>
                                    <View style={styles.billRight}>
                                        <Text style={styles.billAmount}>{formatFcfa(Number(bill.amount_due))}</Text>
                                        <Badge label={badge.label} tone={badge.tone} />
                                    </View>
                                </View>
                            );
                        })
                    )}

                    {history.statement_urls ? (
                        <>
                            <Text style={styles.statementLabel}>Statement of account</Text>
                            <View style={styles.statementRow}>
                                {(['6', '12', '24'] as const).map((months) => (
                                    <Pressable
                                        key={months}
                                        accessibilityRole="button"
                                        accessibilityLabel={`Open the last ${months} months statement`}
                                        onPress={() => void Linking.openURL(history.statement_urls![months])}
                                        style={styles.statementChip}
                                    >
                                        <Text style={styles.statementChipLabel}>{months} months</Text>
                                    </Pressable>
                                ))}
                            </View>
                        </>
                    ) : null}
                </>
            ) : null}
        </Card>
    );
}

const styles = StyleSheet.create({
    sectionTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textSecondary, marginBottom: spacing.sm },
    muted: { fontSize: fontSize.sm, color: colors.textSecondary },
    billRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: spacing.xs,
        gap: spacing.sm,
    },
    billInfo: { flexShrink: 1 },
    billMonth: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    billNumber: { fontSize: fontSize.xs, color: colors.textSecondary },
    billRight: { alignItems: 'flex-end', gap: 2 },
    billAmount: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    statementLabel: { fontSize: fontSize.xs, fontWeight: '700', color: colors.textSecondary, marginTop: spacing.md, marginBottom: spacing.xs },
    statementRow: { flexDirection: 'row', gap: spacing.sm },
    statementChip: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: touchTarget.floor,
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.accent.customers,
        backgroundColor: colors.surface,
    },
    statementChipLabel: { fontSize: fontSize.sm, fontWeight: '700', color: colors.accent.customers },
});
