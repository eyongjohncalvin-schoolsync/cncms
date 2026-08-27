import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { ScrollView, View, Text, StyleSheet, Alert } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { getQueuedPaymentsCount, getTodayCollectionTotal } from '../../src/db/payments';
import { countCustomers, getZoneSnapshot, type ZoneSnapshot } from '../../src/db/customers';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { getQueuedExpendituresCount } from '../../src/db/expenditures';
import { getNotificationsState, subscribeNotificationsState } from '../../src/notifications/notificationStore';
import { Card } from '../../src/components/ui/Card';
import { StatCard } from '../../src/components/ui/StatCard';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';

export default function HomeScreen() {
    const { user, role, logout } = useAuth();
    const router = useRouter();
    const notificationsState = useSyncExternalStore(subscribeNotificationsState, getNotificationsState);

    const [todayTotal, setTodayTotal] = useState<number | null>(null);
    const [customerCount, setCustomerCount] = useState<number | null>(null);
    const [zoneSnapshot, setZoneSnapshot] = useState<ZoneSnapshot | null>(null);

    const refresh = useCallback(() => {
        void getTodayCollectionTotal().then(setTodayTotal);
        void countCustomers().then(setCustomerCount);
        void getZoneSnapshot().then(setZoneSnapshot);
    }, []);

    useFocusEffect(refresh);

    // Also refresh right after any sync completes (lastSyncAt changing is a
    // reliable signal a pull just finished), so the customer count updates
    // without the agent needing to leave and re-enter the tab.
    useEffect(() => {
        let lastSeen = getSyncState().lastSyncAt;

        return subscribeSyncState(() => {
            const current = getSyncState().lastSyncAt;

            if (current !== lastSeen) {
                lastSeen = current;
                refresh();
            }
        });
    }, [refresh]);

    async function handleLogout() {
        const [queuedPayments, queuedExpenditures] = await Promise.all([
            getQueuedPaymentsCount(),
            getQueuedExpendituresCount(),
        ]);
        const unsynced = queuedPayments + queuedExpenditures;

        const doLogout = () => void logout();

        if (unsynced > 0) {
            Alert.alert(
                'Unsynced payments on this device',
                `${unsynced} recorded item${unsynced === 1 ? '' : 's'} ${unsynced === 1 ? 'has' : 'have'} not synced to the server yet. Signing out keeps them saved on this device — they will sync next time you (or another agent) sign in and go online. Continue?`,
                [
                    { text: 'Cancel', style: 'cancel' },
                    { text: 'Sign out', style: 'destructive', onPress: doLogout },
                ],
            );
        } else {
            doLogout();
        }
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <View style={styles.greetingRow}>
                <View>
                    <Text style={styles.greeting}>Hello, {user?.name.split(' ')[0] ?? 'agent'}</Text>
                    <Text style={styles.role}>{role ?? '—'}</Text>
                </View>
                <Button title="Sign out" variant="ghost" size="default" fullWidth={false} onPress={handleLogout} />
            </View>

            {/*
              Hero balance card — 2026-08-27 rebrand (mobile-app-react-native.md
              dated section has the full research writeup). This is the single
              most MTN-MoMo-like pattern this pass's research turned up: a
              bold, high-contrast, elevated summary card carrying the one
              figure a screen most wants an agent's eye to land on first —
              not just a bigger number, a genuinely different treatment (solid
              fill, not a white card with a colored stripe). Built on Card's
              new `variant="filled"` (Card.tsx) rather than one-off styling
              here, so a future screen wanting the same "hero" treatment reuses
              this exact primitive instead of re-deriving it from scratch.
              Deliberately the ONLY card on this screen that gets this
              treatment — see the "Zone arrears outstanding" card just below,
              which stays the outlined style on purpose (comment there).
            */}
            <Card variant="filled">
                <View style={styles.heroTopRow}>
                    <Text style={styles.heroLabel}>TODAY'S COLLECTION</Text>
                    <View style={styles.heroGlyphBadge}>
                        <Text style={styles.heroGlyphText}>₣</Text>
                    </View>
                </View>
                <Text style={styles.heroValue}>{todayTotal === null ? '—' : formatFcfa(todayTotal)}</Text>
                <Text style={styles.heroHint}>From this device — renders instantly, even offline.</Text>
            </Card>

            {/*
              Zone-arrears card — mobile-app-react-native.md §4 calls for a
              "3-tile zone snapshot (arrears count/total, disconnected-but-
              visitable count)" on Home; it existed only in the design doc,
              not in this screen, until 2026-08-27 (see that file's dated
              addendum). Deliberately NOT laid out as 3 equal StatCard tiles
              — the product owner's brief specifically asked for clearer
              hierarchy on "the arrears/collection-critical numbers," so the
              arrears TOTAL (what's actually left to collect today) gets a
              full-width, large-numeral treatment right below "Today's
              collection" — a paired "done vs. still owed" read at the very
              top of the screen (2026-08-27: the two cards' fill styles now
              deliberately differ — see that section's own comment below for
              why). The owes-money COUNT
              becomes this card's hint line instead of a separate tile.
              Same red-for-arrears convention already used on the Customers
              list and Customer Detail (colors.danger only when > 0 — a
              fully paid-up zone shouldn't render an alarming color for a
              routine, good state). Tapping jumps straight into Customers
              pre-filtered, same shortcut Home's own "Continue your route"
              card already offers further down — this is just a faster,
              higher-visibility path to the identical destination.

              2026-08-27: deliberately did NOT give this the new hero
              (variant="filled") treatment the collection card above just
              got, even though arrears is arguably just as important a
              number. A zone carrying some arrears is the normal, expected
              day-to-day state, not a problem — the same "calm until
              actually urgent" principle §5 applies to the sync strip
              (amber, not red, for normal offline operation) applies here:
              a full-bleed red hero block would read as a standing alarm
              every single time an agent opens the app, even on an entirely
              routine day. Keeping this an outlined card with a red stripe
              (only when > 0) preserves the existing, already-correct
              "red means real money owed, not a system error" signal
              without escalating its visual weight into looking like
              something is wrong.
            */}
            <Card
                onPress={() => router.push('/(tabs)/customers?filter=owes-money')}
                accentColor={zoneSnapshot && zoneSnapshot.arrearsTotal > 0 ? colors.danger : undefined}
            >
                <Text style={styles.totalLabel}>Zone arrears outstanding</Text>
                <Text
                    style={[
                        styles.totalValue,
                        zoneSnapshot && zoneSnapshot.arrearsTotal > 0
                            ? styles.totalValueDanger
                            : styles.totalValueNeutral,
                    ]}
                >
                    {zoneSnapshot === null ? '—' : formatFcfa(zoneSnapshot.arrearsTotal)}
                </Text>
                <Text style={styles.totalHint}>
                    {zoneSnapshot === null
                        ? 'Loading…'
                        : zoneSnapshot.owesMoneyCount === 0
                          ? 'Every cached customer is paid up — tap to browse'
                          : `${zoneSnapshot.owesMoneyCount} customer${zoneSnapshot.owesMoneyCount === 1 ? '' : 's'} owe money — tap to view`}
                </Text>
            </Card>

            <View style={styles.statRow}>
                <StatCard
                    label="Disconnected"
                    value={zoneSnapshot === null ? '—' : String(zoneSnapshot.disconnectedCount)}
                    hint="Still on your route"
                    tone="amber"
                    onPress={() => router.push('/(tabs)/customers?filter=disconnected')}
                />
                <StatCard
                    label="Customers cached"
                    value={customerCount === null ? '—' : String(customerCount)}
                    hint="In your zone, synced to this device"
                    tone="customers"
                />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Quick actions</Text>
                <Button
                    title="Record a payment"
                    onPress={() => router.push('/(tabs)/record-payment')}
                    size="large"
                />
                {/* Secondary CTAs, one tap deeper — not top-level tabs, per
                    mobile-app-react-native.md §4 / complaint-desk.md §7's
                    explicit "not a 5th tab" decision for Log a Complaint. */}
                <Button
                    title="Record an expense"
                    variant="secondary"
                    onPress={() => router.push('/record-expense')}
                />
                <Button
                    title="Log a complaint"
                    variant="secondary"
                    onPress={() => router.push('/log-complaint')}
                />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Notifications</Text>
                <Card onPress={() => router.push('/notifications')} accentColor={colors.accent.complaint}>
                    <View style={styles.notificationsRow}>
                        <View>
                            <Text style={styles.linkTitle}>
                                {notificationsState.unreadCount === 0
                                    ? 'All caught up'
                                    : `${notificationsState.unreadCount} unread`}
                            </Text>
                            <Text style={styles.linkSubtitle}>Complaint updates and staff broadcasts</Text>
                        </View>
                        {notificationsState.unreadCount > 0 ? (
                            <Badge label={String(notificationsState.unreadCount)} tone="error" />
                        ) : null}
                    </View>
                </Card>
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Get around</Text>
                <Card onPress={() => router.push('/(tabs)/customers?filter=owes-money')} accentColor={colors.accent.customers}>
                    <Text style={styles.linkTitle}>Continue your route</Text>
                    <Text style={styles.linkSubtitle}>Jump into customers who still owe money</Text>
                </Card>
                <Card onPress={() => router.push('/(tabs)/customers')}>
                    <Text style={styles.linkTitle}>Customers</Text>
                    <Text style={styles.linkSubtitle}>Browse your zone's customers</Text>
                </Card>
                <Card onPress={() => router.push('/(tabs)/history')}>
                    <Text style={styles.linkTitle}>My recorded payments</Text>
                    <Text style={styles.linkSubtitle}>See what you've submitted and its verification status</Text>
                </Card>
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    greetingRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
    },
    greeting: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    role: { fontSize: fontSize.sm, color: colors.textSecondary, textTransform: 'capitalize' },
    // Hero card (see the render-side comment above for why this exists).
    heroTopRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    heroLabel: {
        fontSize: fontSize.sm,
        fontWeight: '700',
        // Solid, fully-opaque near-white (emerald-50) rather than a
        // translucent white — verified ~7.29:1 against this card's
        // emerald-800 fill (mobile-app-react-native.md dated section has
        // the full ratio table). A translucent/rgba white would blend with
        // the fill color into something not directly measurable this way,
        // and reads closer to the glassmorphism this app's §6 rules out —
        // an opaque, slightly-tinted solid avoids both problems while still
        // reading as "a lighter white" for label/hint-vs-value hierarchy.
        // NOTE: this exact hex is only correct paired with accent.payment's
        // current emerald-800 — a future hero card using a different
        // fillColor needs its own verified pairing, not a copy of this one.
        color: '#ECFDF5',
        letterSpacing: 0.6,
    },
    heroGlyphBadge: {
        width: 36,
        height: 36,
        borderRadius: 18,
        backgroundColor: colors.textInverse,
        alignItems: 'center',
        justifyContent: 'center',
    },
    heroGlyphText: { fontSize: fontSize.md, fontWeight: '800', color: colors.accent.payment },
    // fontSize.display (40, tokens.ts) on pure white — verified ~7.68:1
    // against this card's emerald-800 fill, comfortably AAA.
    heroValue: { fontSize: fontSize.display, fontWeight: '800', color: colors.textInverse, marginTop: spacing.sm },
    heroHint: { fontSize: fontSize.xs, color: '#ECFDF5', marginTop: spacing.xs },
    totalLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    totalValue: { fontSize: fontSize.display, fontWeight: '800', color: colors.accent.payment, marginTop: spacing.xs },
    // Overrides for the "Zone arrears outstanding" card, which reuses
    // totalValue's sizing but not its green (that color means "money
    // collected," the opposite of what this card shows).
    totalValueDanger: { color: colors.danger },
    totalValueNeutral: { color: colors.textPrimary },
    totalHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
    statRow: { flexDirection: 'row', gap: spacing.md },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notificationsRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    linkTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    linkSubtitle: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
});
