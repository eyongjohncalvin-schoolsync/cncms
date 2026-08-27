import { useCallback, useEffect, useState } from 'react';
import { View, Text, FlatList, Pressable, StyleSheet } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { Badge, type BadgeTone } from '../../src/components/ui/Badge';
import { getRecentPayments } from '../../src/db/payments';
import { getAllCustomers } from '../../src/db/customers';
import { subscribeSyncState } from '../../src/sync/syncStore';
import {
    filterPaymentsByStatus,
    filterLabel,
    formatFrequency,
    PAYMENT_STATUS_FILTERS,
    type PaymentStatusFilter,
} from '../../src/utils/paymentFilters';
import { formatFcfa, formatShortDate } from '../../src/utils/format';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../src/theme/tokens';
import type { LocalPayment, VerificationStatus } from '../../src/types/db';

const STATUS_BADGE_TONE: Record<VerificationStatus, BadgeTone> = {
    pending: 'pending',
    verified: 'verified',
    rejected: 'rejected',
};

const STATUS_BADGE_LABEL: Record<VerificationStatus, string> = {
    pending: 'Pending',
    verified: 'Verified',
    rejected: 'Rejected',
};

/**
 * "My Recorded Payments" — this device's own outbox+history table
 * (src/db/payments.ts), newest first, per mobile-app-react-native.md §4.
 * Every row here originated on this device by construction, so there is no
 * separate "mine" filter to apply server-side. Rejected rows are tappable
 * to reveal the office's rejection reason — purely informational, no
 * edit/resubmit affordance, per §2's explicit v1 constraint. No
 * "Contact office" tap-to-call chip: confirmed no company/contact data is
 * synced to this device anywhere (pull() only returns customers/payments;
 * there is no local `companies` cache), so that affordance is omitted
 * rather than hardcoding a number, per the brief's own fallback.
 *
 * Live-refreshes on every sync-state change (not just on tab focus): a
 * pending row's `verification_status` can flip to verified/rejected purely
 * from a background pull landing (e.g. the periodic in-foreground timer,
 * §2) while the agent is sitting on this exact tab looking at it — without
 * this, they'd only see the update after leaving and returning to History.
 * Same `subscribeSyncState`-triggered refetch pattern already used by
 * app/sync-status.tsx, not a new mechanism.
 */
export default function HistoryScreen() {
    const [payments, setPayments] = useState<LocalPayment[]>([]);
    const [customerNames, setCustomerNames] = useState<Map<string, string>>(new Map());
    const [filter, setFilter] = useState<PaymentStatusFilter>('all');
    const [expandedLocalUuid, setExpandedLocalUuid] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);

    const refresh = useCallback(() => {
        void Promise.all([getRecentPayments(), getAllCustomers()]).then(([recentPayments, customers]) => {
            setPayments(recentPayments);
            setCustomerNames(new Map(customers.map((c) => [c.uuid, c.name])));
            setLoaded(true);
        });
    }, []);

    useFocusEffect(
        useCallback(() => {
            refresh();
        }, [refresh]),
    );

    useEffect(() => subscribeSyncState(refresh), [refresh]);

    const filtered = filterPaymentsByStatus(payments, filter);

    if (loaded && payments.length === 0) {
        return (
            <View style={styles.flex}>
                <EmptyState
                    title="My Recorded Payments"
                    subtitle="Payments you record will show up here, newest first, with their verification status."
                />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <View style={styles.filterRow}>
                {PAYMENT_STATUS_FILTERS.map((option) => {
                    const active = option === filter;

                    return (
                        <Pressable
                            key={option}
                            accessibilityRole="button"
                            accessibilityState={{ selected: active }}
                            onPress={() => setFilter(option)}
                            style={[styles.chip, active && styles.chipActive]}
                        >
                            <Text style={[styles.chipText, active && styles.chipTextActive]}>{filterLabel(option)}</Text>
                        </Pressable>
                    );
                })}
            </View>

            {filtered.length === 0 ? (
                <View style={styles.emptyFiltered}>
                    <Text style={styles.emptyFilteredText}>No {filterLabel(filter).toLowerCase()} payments.</Text>
                </View>
            ) : (
                <FlatList
                    data={filtered}
                    keyExtractor={(item) => item.local_uuid}
                    contentContainerStyle={styles.listContent}
                    renderItem={({ item }) => {
                        const isRejected = item.verification_status === 'rejected';
                        const isExpanded = expandedLocalUuid === item.local_uuid;
                        const customerName = customerNames.get(item.customer_uuid) ?? 'Unknown customer';

                        return (
                            <Pressable
                                disabled={!isRejected}
                                onPress={() => setExpandedLocalUuid(isExpanded ? null : item.local_uuid)}
                                style={styles.row}
                            >
                                <View style={styles.rowTop}>
                                    <View style={styles.rowMain}>
                                        <Text style={styles.customerName} numberOfLines={1}>
                                            {customerName}
                                        </Text>
                                        <Text style={styles.rowMeta}>
                                            {formatShortDate(item.created_at)} · {formatFrequency(item.frequency, item.months)}
                                        </Text>
                                    </View>
                                    <View style={styles.rowEnd}>
                                        <Text style={styles.amount}>{formatFcfa(item.amount)}</Text>
                                        <Badge
                                            label={STATUS_BADGE_LABEL[item.verification_status]}
                                            tone={STATUS_BADGE_TONE[item.verification_status]}
                                        />
                                    </View>
                                </View>
                                {isRejected && isExpanded ? (
                                    <View style={styles.reasonBox}>
                                        <Text style={styles.reasonLabel}>Reason from the office</Text>
                                        <Text style={styles.reasonText}>
                                            {item.rejection_reason ?? 'No reason was recorded for this rejection.'}
                                        </Text>
                                    </View>
                                ) : null}
                            </Pressable>
                        );
                    }}
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    filterRow: {
        flexDirection: 'row',
        gap: spacing.sm,
        paddingHorizontal: spacing.lg,
        paddingTop: spacing.md,
        paddingBottom: spacing.sm,
    },
    chip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        // 48dp floor per mobile-app-react-native.md §6 — tapped on nearly
        // every visit to this screen (same audit stage 1 already ran on
        // Customers list's identical filter-chip pattern), so an actual
        // resize is the right trade-off here, not hitSlop.
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: {
        borderColor: colors.accent.history,
        backgroundColor: colors.accent.history,
    },
    chipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipTextActive: { color: colors.textInverse },
    listContent: { paddingHorizontal: spacing.lg, paddingBottom: spacing.xxl, gap: spacing.sm },
    row: {
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.lg,
        padding: spacing.md,
        backgroundColor: colors.surface,
        marginBottom: spacing.sm,
    },
    rowTop: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.md },
    rowMain: { flex: 1, gap: 2 },
    customerName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    rowMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
    rowEnd: { alignItems: 'flex-end', gap: spacing.xs },
    amount: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    reasonBox: {
        marginTop: spacing.sm,
        paddingTop: spacing.sm,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        gap: 2,
    },
    reasonLabel: { fontSize: fontSize.xs, fontWeight: '700', color: colors.status.rejectedFg },
    reasonText: { fontSize: fontSize.sm, color: colors.textPrimary },
    emptyFiltered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
    emptyFilteredText: { fontSize: fontSize.md, color: colors.textSecondary },
});
