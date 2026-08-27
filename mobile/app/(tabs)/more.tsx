import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Card } from '../../src/components/ui/Card';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';

interface MoreLink {
    title: string;
    subtitle: string;
    href: string;
    accentColor?: string;
}

interface MoreSection {
    title: string;
    links: MoreLink[];
}

/**
 * "More" — the 5th tab, added 2026-08-27 alongside 7 new screens (Settings,
 * Reports, Resources, Zones, Agent Profile, Disconnections, Complaints)
 * built in parallel this session. mobile-app-react-native.md §4 previously
 * settled on 4 tabs specifically to keep Log a Complaint from getting its
 * own dedicated tab for a single feature ("not a 5th tab" — complaint-desk.md
 * §7) — that reasoning doesn't extend to a genuine grab-bag of 7+ secondary
 * destinations. A single "More" tab is the standard resolution once an app
 * grows past what 4 top-level destinations can hold (the same shape MTN
 * MoMo/most mobile-money apps use), and it's a real UX improvement on its
 * own: Notifications and Sync Status previously had no consistent home —
 * only ad-hoc Card links buried on Home — they're consolidated here too.
 *
 * Deliberately a plain list, not a grid — every entry needs a subtitle to
 * be legible on first use (these are secondary/infrequent destinations,
 * unlike Home's icon-only tab bar), and a list keeps the 48dp touch-target
 * floor trivial to guarantee via Card's own row padding.
 */
export default function MoreScreen() {
    const router = useRouter();

    const sections: MoreSection[] = [
        {
            title: 'Field tools',
            links: [
                {
                    title: 'Disconnections',
                    subtitle: 'Customers flagged for non-payment in your zone',
                    href: '/disconnections',
                    accentColor: colors.danger,
                },
                {
                    title: 'Zones',
                    subtitle: 'Your zone, and a lookup of every other zone',
                    href: '/zones',
                    accentColor: colors.accent.customers,
                },
                {
                    title: 'Complaints',
                    subtitle: 'Complaints you’ve submitted and their status',
                    href: '/complaints',
                    accentColor: colors.accent.complaint,
                },
                {
                    title: 'Resources',
                    subtitle: 'Your recorded expenditures',
                    href: '/resources',
                    accentColor: colors.accent.expense,
                },
                {
                    title: 'Reports',
                    subtitle: 'Your collection totals and zone snapshot',
                    href: '/reports',
                    accentColor: colors.accent.history,
                },
            ],
        },
        {
            title: 'Account',
            links: [
                {
                    title: 'My Profile',
                    subtitle: 'Your own agent record',
                    href: '/agent-profile',
                },
                {
                    title: 'Settings',
                    subtitle: 'Profile, app version, sign out',
                    href: '/settings',
                },
                {
                    title: 'Notifications',
                    subtitle: 'Complaint updates and staff broadcasts',
                    href: '/notifications',
                },
                {
                    title: 'Sync Status',
                    subtitle: 'Pending uploads and last sync time',
                    href: '/sync-status',
                },
            ],
        },
    ];

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Text style={styles.heading}>More</Text>

            {sections.map((section) => (
                <View key={section.title} style={styles.section}>
                    <Text style={styles.sectionTitle}>{section.title}</Text>
                    {section.links.map((link) => (
                        <Card key={link.href} onPress={() => router.push(link.href as never)} accentColor={link.accentColor}>
                            <Text style={styles.linkTitle}>{link.title}</Text>
                            <Text style={styles.linkSubtitle}>{link.subtitle}</Text>
                        </Card>
                    ))}
                </View>
            ))}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    heading: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    linkTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    linkSubtitle: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
});
