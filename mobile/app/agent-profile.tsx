import { useCallback, useState } from 'react';
import { ActivityIndicator, Image, Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { EmptyState } from '../src/components/ui/EmptyState';
import { fetchMyAgentProfile, type AgentMeApi } from '../src/api/agents';
import { extractErrorMessage } from '../src/api/client';
import { formatFcfa, formatShortDate } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';

type LoadState = 'loading' | 'loaded' | 'not_found' | 'error';

const STATUS_BADGE: Record<string, { label: string; tone: BadgeTone }> = {
    active: { label: 'Active', tone: 'verified' },
    inactive: { label: 'Inactive', tone: 'neutral' },
};

/**
 * "My Profile" — read-only display of the CURRENTLY LOGGED-IN agent's own
 * Agent record: name, zone, photo, and employment info (own salary
 * included — it's their own data).
 *
 * Deliberately scoped narrower than App\Policies\AgentPolicy technically
 * allows. AgentPolicy::view()/viewAny() are open to "everyone" (any
 * authenticated tenant role) — a coarse grant that makes sense for the web
 * admin panel's office-staff agent roster, but has no product justification
 * carried onto a field agent's own phone: a device that can be lost,
 * borrowed, or glanced at by someone else. Showing every OTHER agent's
 * salary, marital status, and photo here would be a real, avoidable
 * exposure the web policy's coarseness doesn't force on this screen. So
 * this screen never lists or looks up any agent by uuid — it only ever
 * calls GET /api/v1/agents/me, an endpoint that resolves strictly from the
 * authenticated user's own id server-side
 * (Agent::where('user_id', auth()->id())->firstOrFail() — see
 * App\Http\Controllers\Api\AgentController::me()) and has no uuid parameter
 * to redirect elsewhere. There is no roster, no "view another agent" path,
 * and no way to reach one from here, by design.
 *
 * Read-only: App\Policies\AgentPolicy::update() is super/admin/manager
 * only, so this screen has no edit affordance at all, matching
 * PaymentPolicy's own office-only update precedent elsewhere in this app.
 */
export default function AgentProfileScreen() {
    const [state, setState] = useState<LoadState>('loading');
    const [profile, setProfile] = useState<AgentMeApi | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const load = useCallback(async () => {
        setState('loading');

        try {
            const response = await fetchMyAgentProfile();
            setProfile(response.data);
            setState('loaded');
        } catch (error: unknown) {
            const status = (error as { response?: { status?: number } })?.response?.status;

            if (status === 404) {
                setState('not_found');
                return;
            }

            setErrorMessage(extractErrorMessage(error, "Couldn't load your profile."));
            setState('error');
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    if (state === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'My Profile' }} />
                <ActivityIndicator size="large" color={colors.accent.home} />
            </View>
        );
    }

    if (state === 'not_found') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'My Profile' }} />
                <EmptyState
                    title="No agent record linked to your account"
                    subtitle="Your login isn't linked to a field-agent record. Contact the office if this looks wrong."
                />
            </View>
        );
    }

    if (state === 'error' || !profile) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'My Profile' }} />
                <EmptyState
                    title="Couldn't load your profile"
                    subtitle={errorMessage ?? undefined}
                    actionLabel="Try again"
                    onAction={() => void load()}
                />
            </View>
        );
    }

    const statusBadge = STATUS_BADGE[profile.status] ?? { label: profile.status, tone: 'neutral' as BadgeTone };
    const maritalLabel =
        profile.marital_status === 'yes' ? 'Married' : profile.marital_status === 'no' ? 'Single' : '—';
    const salary = profile.salary !== null ? formatFcfa(parseFloat(profile.salary)) : '—';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'My Profile' }} />

            <View style={styles.headerRow}>
                {profile.picture_url ? (
                    <Image source={{ uri: profile.picture_url }} style={styles.avatar} />
                ) : (
                    <View style={styles.avatarFallback}>
                        <Text style={styles.avatarInitial}>{profile.name.charAt(0).toUpperCase() || '?'}</Text>
                    </View>
                )}
                <View style={styles.headerText}>
                    <Text style={styles.name}>{profile.name}</Text>
                    <Badge label={statusBadge.label} tone={statusBadge.tone} />
                </View>
            </View>

            {profile.phone ? (
                <Card onPress={() => void Linking.openURL(`tel:${profile.phone}`)} accentColor={colors.accent.home}>
                    <Text style={styles.fieldLabelStandalone}>Phone</Text>
                    <Text style={styles.phoneValue}>{profile.phone}</Text>
                    <Text style={styles.phoneHint}>Tap to call</Text>
                </Card>
            ) : null}

            <Card>
                <Text style={styles.sectionTitle}>Zone assignment</Text>
                <View style={styles.fieldList}>
                    <Field label="Zone" value={profile.zone_name ?? '—'} />
                    <Field label="Location" value={profile.location ?? '—'} last />
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Employment</Text>
                <View style={styles.fieldList}>
                    <Field label="Salary" value={salary} />
                    <Field label="Email" value={profile.email ?? '—'} />
                    <Field label="Date of birth" value={profile.dob ? formatShortDate(profile.dob) : '—'} />
                    <Field label="Marital status" value={maritalLabel} />
                    <Field label="Children" value={profile.children !== null ? String(profile.children) : '—'} last />
                </View>
            </Card>
        </ScrollView>
    );
}

function Field({ label, value, last = false }: { label: string; value: string; last?: boolean }) {
    return (
        <View style={[styles.fieldRow, !last && styles.fieldRowDivider]}>
            <Text style={styles.fieldLabel}>{label}</Text>
            <Text style={styles.fieldValue} numberOfLines={1}>
                {value}
            </Text>
        </View>
    );
}

const AVATAR_SIZE = 72;

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    headerRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.lg },
    avatar: { width: AVATAR_SIZE, height: AVATAR_SIZE, borderRadius: AVATAR_SIZE / 2, backgroundColor: colors.surfaceMuted },
    avatarFallback: {
        width: AVATAR_SIZE,
        height: AVATAR_SIZE,
        borderRadius: AVATAR_SIZE / 2,
        backgroundColor: colors.accent.home,
        alignItems: 'center',
        justifyContent: 'center',
    },
    avatarInitial: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textInverse },
    headerText: { flexShrink: 1, gap: spacing.xs },
    name: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    fieldLabelStandalone: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    phoneValue: { fontSize: fontSize.lg, fontWeight: '700', color: colors.accent.home, marginTop: spacing.xs },
    phoneHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, marginBottom: spacing.sm },
    fieldList: { gap: spacing.sm },
    fieldRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: spacing.md,
        paddingBottom: spacing.sm,
    },
    fieldRowDivider: {
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    fieldLabel: { fontSize: fontSize.sm, color: colors.textSecondary },
    fieldValue: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary, flexShrink: 1, textAlign: 'right' },
});
