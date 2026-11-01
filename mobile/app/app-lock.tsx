import { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { TextInput } from '../src/components/ui/TextInput';
import { biometricAvailable, biometricEnabled, checkPin, clearAppLock, isLockEnabled, setBiometric, setPin } from '../src/lock/appLock';
import { pinProblem } from '../src/utils/appLockRules';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';

type Step = 'idle' | 'new' | 'change' | 'off';

/**
 * More → App lock (wave 6): set a PIN so the app asks for it on every
 * open and after a minute away; optionally unlock with the fingerprint
 * too. Changing or removing the PIN asks for the current one first.
 * Signing out removes it.
 */
export default function AppLockScreen() {
    const [enabled, setEnabled] = useState(false);
    const [finger, setFinger] = useState(false);
    const [fingerAvailable, setFingerAvailable] = useState(false);
    const [step, setStep] = useState<Step>('idle');
    const [current, setCurrent] = useState('');
    const [first, setFirst] = useState('');
    const [second, setSecond] = useState('');
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        setEnabled(await isLockEnabled());
        setFinger(await biometricEnabled());
        setFingerAvailable(await biometricAvailable());
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load();
        }, [load]),
    );

    function begin(next: Step) {
        setStep(next);
        setCurrent('');
        setFirst('');
        setSecond('');
        setError(null);
    }

    async function currentIsRight(): Promise<boolean> {
        const result = await checkPin(current);

        if (!result.ok) {
            setError('That is not your current PIN.');
        }

        return result.ok;
    }

    async function save() {
        if (step === 'change' && !(await currentIsRight())) {
            return;
        }

        const problem = pinProblem(first);

        if (problem) {
            setError(problem);

            return;
        }

        if (first !== second) {
            setError('The two PINs are not the same.');

            return;
        }

        await setPin(first);
        begin('idle');
        await load();
        Alert.alert('App lock on', 'The app now asks for this PIN when it opens and after a minute away.');
    }

    async function turnOff() {
        if (!(await currentIsRight())) {
            return;
        }

        await clearAppLock();
        begin('idle');
        await load();
    }

    async function toggleFinger(value: boolean) {
        await setBiometric(value);
        setFinger(value);
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <Text style={styles.lead}>
                {enabled
                    ? 'This phone asks for your PIN when the app opens and after a minute away.'
                    : 'Protect the decisions this phone can make: ask for a PIN when the app opens and after a minute away.'}
            </Text>

            {enabled && step === 'idle' ? (
                <>
                    {fingerAvailable ? (
                        <View style={styles.row}>
                            <Text style={styles.rowLabel}>Unlock with my fingerprint too</Text>
                            <Switch value={finger} onValueChange={(value) => void toggleFinger(value)} accessibilityLabel="Unlock with my fingerprint too" />
                        </View>
                    ) : (
                        <Text style={styles.meta}>No fingerprint is set up on this phone — the PIN is used.</Text>
                    )}
                    <Button title="Change PIN" variant="secondary" onPress={() => begin('change')} />
                    <Button title="Turn the lock off" variant="dangerOutline" onPress={() => begin('off')} />
                </>
            ) : null}

            {!enabled && step === 'idle' ? <Button title="Set a PIN" size="large" onPress={() => begin('new')} /> : null}

            {step === 'change' || step === 'off' ? <TextInput label="Current PIN" value={current} onChangeText={setCurrent} keyboardType="number-pad" secureTextEntry maxLength={6} /> : null}

            {step === 'new' || step === 'change' ? (
                <>
                    <TextInput label="New PIN (4 to 6 digits)" value={first} onChangeText={setFirst} keyboardType="number-pad" secureTextEntry maxLength={6} />
                    <TextInput label="The same PIN again" value={second} onChangeText={setSecond} keyboardType="number-pad" secureTextEntry maxLength={6} />
                </>
            ) : null}

            {error ? <Text style={styles.error}>{error}</Text> : null}

            {step === 'new' || step === 'change' ? <Button title="Save PIN" size="large" onPress={() => void save()} /> : null}
            {step === 'off' ? <Button title="Turn the lock off" variant="danger" onPress={() => void turnOff()} /> : null}
            {step !== 'idle' ? <Button title="Cancel" variant="ghost" onPress={() => begin('idle')} /> : null}

            <Text style={styles.meta}>Forgot it? Choose “Forgot PIN? Sign out” on the lock screen, sign in with your password and set a new one. Signing out always removes the PIN.</Text>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    lead: { fontSize: fontSize.md, color: colors.textPrimary },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: touchTarget.floor },
    rowLabel: { flex: 1, fontSize: fontSize.md, color: colors.textPrimary },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
});
