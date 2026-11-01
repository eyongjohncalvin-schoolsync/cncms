import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Badge } from '../../src/components/ui/Badge';
import { Button } from '../../src/components/ui/Button';
import { DetailRow } from '../../src/components/ui/DetailRow';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { TextInput } from '../../src/components/ui/TextInput';
import { fetchDeskComplaint, resolveComplaint, startComplaint, type DeskComplaint, type DeskPermissions } from '../../src/api/complaintDesk';
import { assignComplaint, fetchTechnicians, type Technician } from '../../src/api/inbox';
import { extractErrorMessage } from '../../src/api/client';
import { refreshInbox } from '../../src/inbox/inboxStore';
import { deskStatus } from '../../src/utils/deskStatus';
import { formatRelativeTime } from '../../src/utils/format';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../src/theme/tokens';

const REASON_LABEL: Record<Technician['reason'], string> = {
    covers: 'Covers this zone',
    anywhere: 'Works anywhere',
    elsewhere: 'Other zones',
};

/**
 * One complaint on the phone (docs/plans/mobile-manager-app.md, wave 3):
 * the technician starts and resolves their job; whoever assigns gives it to
 * a technician or moves it to another (their phone rings at once). Each
 * button appears only when the server says this person may use it, and the
 * same policy decides again when it is pressed.
 */
export default function ComplaintScreen() {
    const router = useRouter();
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const [complaint, setComplaint] = useState<DeskComplaint | null>(null);
    const [can, setCan] = useState<DeskPermissions | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [notes, setNotes] = useState('');
    const [technicians, setTechnicians] = useState<Technician[] | null>(null);
    const [picking, setPicking] = useState(false);

    const load = useCallback(async () => {
        try {
            const result = await fetchDeskComplaint(uuid);
            setComplaint(result.complaint);
            setCan(result.can);
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load this complaint. Check your connection.'));
        }
    }, [uuid]);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    async function act(run: () => Promise<unknown>, done: string) {
        setBusy(true);
        setError(null);

        try {
            await run();
            void refreshInbox();
            Alert.alert('Done', done);
            await load();
        } catch (e) {
            setError(extractErrorMessage(e, 'That did not go through. Try again.'));
        } finally {
            setBusy(false);
        }
    }

    function openPicker() {
        setPicking(true);

        if (technicians === null) {
            fetchTechnicians(uuid)
                .then(setTechnicians)
                .catch((e: unknown) => setError(extractErrorMessage(e, 'Could not load the technicians.')));
        }
    }

    function give(technician: Technician) {
        setPicking(false);
        void act(() => assignComplaint(uuid, technician.uuid, technician.name), `${technician.name} has it now — their phone is ringing.`);
    }

    function resolve() {
        const text = notes.trim();

        if (!text) {
            setError('Write what was done first.');

            return;
        }

        void act(() => resolveComplaint(uuid, text), 'Marked resolved.').then(() => setNotes(''));
    }

    if (!complaint) {
        return error ? (
            <EmptyState title="Not loaded" subtitle={error} actionLabel="Try again" onAction={() => void load()} />
        ) : (
            <View style={styles.loading}>
                <ActivityIndicator />
            </View>
        );
    }

    const badge = deskStatus(complaint);
    const resolved = complaint.status === 'resolved';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Stack.Screen options={{ title: 'Complaint' }} />

            <View style={styles.summary}>
                <View style={styles.row}>
                    <Text style={styles.title}>{complaint.title}</Text>
                    <Badge label={badge.label} tone={badge.tone} />
                </View>
                {complaint.urgent ? <Text style={styles.urgent}>Urgent</Text> : null}
                <Text style={styles.description}>{complaint.description}</Text>
            </View>

            <View style={styles.details}>
                {complaint.customer_name ? <DetailRow label="Customer" value={complaint.customer_name} /> : null}
                <DetailRow label="Zone" value={complaint.zone_name ?? '—'} />
                <DetailRow label="Logged by" value={`${complaint.submitted_by?.name ?? '—'}, ${formatRelativeTime(complaint.created_at)}`} />
                <DetailRow label="Technician" value={complaint.assigned_to?.name ?? 'Nobody yet'} last />
            </View>

            {resolved && complaint.resolution_notes ? (
                <View style={styles.block}>
                    <Text style={styles.blockTitle}>What was done</Text>
                    <Text style={styles.description}>{complaint.resolution_notes}</Text>
                </View>
            ) : null}

            {error ? <Text style={styles.error}>{error}</Text> : null}

            {can?.start ? <Button title="Start this job" size="large" loading={busy} onPress={() => void act(() => startComplaint(uuid), 'Marked started — the office can see you’re on it.')} /> : null}

            {can?.resolve ? (
                <View style={styles.block}>
                    <TextInput label="What was done" value={notes} onChangeText={setNotes} multiline placeholder="e.g. Replaced the splitter at the pole" />
                    <Button title="Mark resolved" variant="secondary" loading={busy} onPress={resolve} />
                </View>
            ) : null}

            {can?.assign && !resolved ? (
                <View style={styles.block}>
                    {picking ? (
                        <>
                            <Text style={styles.blockTitle}>Give it to</Text>
                            {technicians === null ? <ActivityIndicator /> : null}
                            {technicians?.length === 0 ? <Text style={styles.meta}>Nobody in this branch resolves complaints yet. Set technicians on the website.</Text> : null}
                            {technicians?.map((technician) => (
                                <Pressable
                                    key={technician.uuid}
                                    accessibilityRole="button"
                                    disabled={busy || technician.uuid === complaint.assigned_to?.uuid}
                                    onPress={() => give(technician)}
                                    style={[styles.technician, technician.uuid === complaint.assigned_to?.uuid && styles.technicianCurrent]}
                                >
                                    <Text style={styles.technicianName}>
                                        {technician.name}
                                        {technician.uuid === complaint.assigned_to?.uuid ? ' (has it now)' : ''}
                                    </Text>
                                    <Text style={styles.meta}>
                                        {REASON_LABEL[technician.reason]} · {technician.open} open
                                    </Text>
                                </Pressable>
                            ))}
                            <Button title="Cancel" variant="ghost" onPress={() => setPicking(false)} />
                        </>
                    ) : (
                        <Button title={complaint.assigned_to ? 'Give to another technician' : 'Assign a technician'} variant={complaint.assigned_to ? 'secondary' : 'primary'} disabled={busy} onPress={openPicker} />
                    )}
                </View>
            ) : null}

            {resolved ? <Button title="Back" variant="ghost" onPress={() => router.back()} /> : null}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    loading: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    summary: { gap: spacing.xs },
    row: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm },
    title: { flex: 1, fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    urgent: { fontSize: fontSize.sm, fontWeight: '800', color: colors.danger },
    description: { fontSize: fontSize.md, color: colors.textPrimary },
    details: { borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: spacing.md },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    block: { gap: spacing.sm },
    blockTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    technician: { minHeight: touchTarget.floor, padding: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, gap: 2 },
    technicianCurrent: { opacity: 0.6 },
    technicianName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
});
