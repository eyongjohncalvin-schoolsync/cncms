import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Alert, AppState, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { Button } from '../components/ui/Button';
import { CompanyLogo } from '../components/ui/CompanyLogo';
import { TextInput } from '../components/ui/TextInput';
import { colors } from '../theme/colors';
import { fontSize, radius, spacing } from '../theme/tokens';
import { attemptsLeftMessage, shouldLockOnReturn } from '../utils/appLockRules';
import { biometricAvailable, biometricEnabled, checkPin, clearAppLock, isLockEnabled, unlockWithBiometric } from './appLock';

type LockState = 'checking' | 'locked' | 'open';

/**
 * The app lock (docs/plans/mobile-manager-app.md, wave 6): when the
 * signed-in person has set a PIN, the app is covered on every open and
 * when it comes back after a minute away, until the PIN or their
 * fingerprint is given. The screens stay mounted underneath, so nothing
 * typed is lost. Too many wrong PINs, or "Forgot PIN", signs the phone
 * out — the password then lets them back in and they set a new PIN.
 */
export function LockGate({ children }: { children: ReactNode }) {
    const { status, logout } = useAuth();
    const [state, setState] = useState<LockState>('checking');
    const [pin, setPin] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [fingerprint, setFingerprint] = useState(false);
    const backgroundSince = useRef<number | null>(null);

    const tryFingerprint = useCallback(async () => {
        if (await unlockWithBiometric()) {
            setPin('');
            setError(null);
            setState('open');
        }
    }, []);

    const lockIfEnabled = useCallback(async () => {
        if (!(await isLockEnabled())) {
            setState('open');

            return;
        }

        setState('locked');
        const useFinger = (await biometricEnabled()) && (await biometricAvailable());
        setFingerprint(useFinger);

        if (useFinger) {
            void tryFingerprint();
        }
    }, [tryFingerprint]);

    // On every open of a signed-in app.
    useEffect(() => {
        if (status === 'authenticated') {
            void lockIfEnabled();
        } else if (status === 'unauthenticated') {
            setState('open');
        }
    }, [status, lockIfEnabled]);

    // Back after a minute away.
    useEffect(() => {
        const subscription = AppState.addEventListener('change', (next) => {
            if (next === 'background') {
                backgroundSince.current = Date.now();
            } else if (next === 'active') {
                if (status === 'authenticated' && shouldLockOnReturn(backgroundSince.current, Date.now())) {
                    void lockIfEnabled();
                }
                backgroundSince.current = null;
            }
        });

        return () => subscription.remove();
    }, [status, lockIfEnabled]);

    async function unlock() {
        setBusy(true);

        try {
            const result = await checkPin(pin);
            setPin('');

            if (result.ok) {
                setError(null);
                setState('open');

                return;
            }

            const message = attemptsLeftMessage(result.failedAttempts);

            if (message === null) {
                await signOut('Too many wrong PINs — this phone is signed out. Sign in with your password and set a new PIN.');

                return;
            }

            setError(message);
        } finally {
            setBusy(false);
        }
    }

    async function signOut(message: string) {
        await clearAppLock();
        await logout();
        setState('open');
        Alert.alert('Signed out', message);
    }

    // Covered from the moment a session is known until the check says open — no glimpse of the app first.
    const covered = status === 'authenticated' && state !== 'open';

    return (
        <View style={styles.flex}>
            {children}
            {covered ? (
                <View style={styles.cover} accessibilityViewIsModal>
                    {state === 'locked' ? (
                        <View style={styles.box}>
                            <CompanyLogo size={56} />
                            <Text style={styles.title}>Enter your PIN</Text>
                            <TextInput label="PIN" value={pin} onChangeText={setPin} keyboardType="number-pad" secureTextEntry maxLength={6} autoFocus={!fingerprint} />
                            {error ? <Text style={styles.error}>{error}</Text> : null}
                            <Button title="Unlock" size="large" loading={busy} disabled={pin.length < 4} onPress={() => void unlock()} />
                            {fingerprint ? <Button title="Use fingerprint" variant="secondary" onPress={() => void tryFingerprint()} /> : null}
                            <Button
                                title="Forgot PIN? Sign out"
                                variant="ghost"
                                onPress={() =>
                                    Alert.alert('Sign out?', 'You sign in again with your password, then set a new PIN.', [
                                        { text: 'Cancel', style: 'cancel' },
                                        { text: 'Sign out', style: 'destructive', onPress: () => void signOut('Sign in with your password, then set a new PIN in More → App lock.') },
                                    ])
                                }
                            />
                        </View>
                    ) : null}
                </View>
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1 },
    cover: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: colors.background, justifyContent: 'center', padding: spacing.lg },
    box: { gap: spacing.md, alignItems: 'stretch' },
    title: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary, textAlign: 'center' },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
});
