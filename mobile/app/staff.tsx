import { useCallback, useMemo, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { Badge } from '../src/components/ui/Badge';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { TextInput } from '../src/components/ui/TextInput';
import { activateStaff, changeRole, deactivateStaff, fetchStaff, unblockStaff, type StaffList, type StaffMember } from '../src/api/today';
import { extractErrorMessage } from '../src/api/client';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';

/**
 * Staff on the phone (docs/plans/mobile-manager-app.md, wave 5): who works
 * here, and the everyday decisions — change a role, deactivate or
 * reactivate, lift a sign-in block. Same rules as the website's Users page
 * (never your own account, never the last owner, never someone still
 * holding money); creating accounts and the role matrix stay on the web.
 */
export default function StaffScreen() {
    const [list, setList] = useState<StaffList | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [open, setOpen] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState('');

    const load = useCallback(async () => {
        setRefreshing(true);

        try {
            setList(await fetchStaff());
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load the staff. Check your connection.'));
        } finally {
            setRefreshing(false);
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    const shown = useMemo(() => {
        const term = search.trim().toLowerCase();

        return (list?.staff ?? []).filter((member) => term === '' || member.name.toLowerCase().includes(term) || member.role_label.toLowerCase().includes(term));
    }, [list, search]);

    function confirm(title: string, message: string, label: string, task: () => Promise<string>, destructive = false) {
        Alert.alert(title, message, [
            { text: 'Cancel', style: 'cancel' },
            {
                text: label,
                style: destructive ? 'destructive' : 'default',
                onPress: async () => {
                    setBusy(true);
                    setError(null);

                    try {
                        const message = await task();
                        Alert.alert('Done', message);
                        await load();
                    } catch (e) {
                        setError(extractErrorMessage(e, 'That did not go through. Try again.'));
                    } finally {
                        setBusy(false);
                    }
                },
            },
        ]);
    }

    if (!list) {
        return error ? <EmptyState title="Not loaded" subtitle={error} actionLabel="Try again" onAction={() => void load()} /> : <View style={styles.flex} />;
    }

    function renderMember(member: StaffMember) {
        const expanded = open === member.id && member.can_manage && list!.can_manage;
        const passive = member.status !== 'active';

        return (
            <Card key={member.id} accentColor={passive ? colors.textSecondary : colors.accent.customers} onPress={member.can_manage && list!.can_manage ? () => setOpen(expanded ? null : member.id) : undefined}>
                <View style={styles.row}>
                    <Text style={styles.name}>
                        {member.name}
                        {member.is_me ? ' (you)' : ''}
                    </Text>
                    {passive ? <Badge label="Deactivated" tone="neutral" /> : null}
                    {member.sign_in_blocked_until ? <Badge label="Sign-in blocked" tone="error" /> : null}
                </View>
                <Text style={styles.meta}>
                    {member.role_label}
                    {member.job_title ? ` · ${member.job_title}` : ''}
                    {member.branch_name ? ` · ${member.branch_name}` : ''}
                </Text>

                {expanded ? (
                    <View style={styles.actions}>
                        {member.sign_in_blocked_until ? (
                            <Button
                                title="Let them sign in now"
                                disabled={busy}
                                onPress={() =>
                                    confirm(
                                        'Lift the sign-in block?',
                                        `${member.name} typed too many wrong passwords (blocked until ${new Date(member.sign_in_blocked_until!).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}). Only lift it if you know it was them.`,
                                        'Lift block',
                                        () => unblockStaff(member.id),
                                    )
                                }
                            />
                        ) : null}

                        {!passive ? (
                            <>
                                <Text style={styles.blockTitle}>Role</Text>
                                <View style={styles.roles}>
                                    {list!.roles.map((role) => (
                                        <Pressable
                                            key={role.name}
                                            accessibilityRole="radio"
                                            accessibilityState={{ checked: role.name === member.role }}
                                            disabled={busy || role.name === member.role}
                                            onPress={() => confirm('Change their role?', `${member.name} becomes ${role.label}. What they can see and do changes at once.`, 'Change', () => changeRole(member.id, role.name))}
                                            style={[styles.role, role.name === member.role && styles.roleCurrent]}
                                        >
                                            <Text style={[styles.roleText, role.name === member.role && styles.roleTextCurrent]}>{role.label}</Text>
                                        </Pressable>
                                    ))}
                                </View>
                                <Button
                                    title="Deactivate"
                                    variant="dangerOutline"
                                    disabled={busy}
                                    onPress={() =>
                                        confirm('Deactivate this account?', `${member.name} is signed out of every phone and browser now and can't sign in until reactivated.`, 'Deactivate', () => deactivateStaff(member.id), true)
                                    }
                                />
                            </>
                        ) : (
                            <Button title="Reactivate" disabled={busy} onPress={() => confirm('Reactivate this account?', `${member.name} can sign in again.`, 'Reactivate', () => activateStaff(member.id))} />
                        )}
                    </View>
                ) : null}
            </Card>
        );
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load()} />}>
            <Stack.Screen options={{ title: 'Staff' }} />

            {error ? <Text style={styles.error}>{error}</Text> : null}
            <TextInput label="Find" value={search} onChangeText={setSearch} placeholder="Name or role" />
            {list.can_manage ? <Text style={styles.meta}>Tap someone to change their role, deactivate them or lift a sign-in block. New accounts are made on the website.</Text> : null}

            {shown.map(renderMember)}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xxl },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs, flexWrap: 'wrap' },
    name: { flex: 1, fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    actions: { gap: spacing.sm, marginTop: spacing.md, paddingTop: spacing.md, borderTopWidth: 1, borderTopColor: colors.border },
    blockTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    roles: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
    role: { minHeight: touchTarget.floor, justifyContent: 'center', paddingHorizontal: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border },
    roleCurrent: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    roleText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    roleTextCurrent: { color: colors.textInverse },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
});
