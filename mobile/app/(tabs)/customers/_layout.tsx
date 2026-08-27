import { Stack } from 'expo-router';
import { colors } from '../../../src/theme/colors';

/**
 * Nested stack for the Customers tab so Customer Detail gets a real native
 * header (back button + title) instead of inheriting the tab bar's
 * headerShown:false from (tabs)/_layout.tsx. See mobile-app-react-native.md
 * §4 — the sync-status strip stays visible above this because it's mounted
 * once in (tabs)/_layout.tsx, outside of and above this nested navigator.
 */
export default function CustomersStackLayout() {
    return (
        <Stack
            screenOptions={{
                headerShown: true,
                headerStyle: { backgroundColor: colors.background },
                headerTintColor: colors.textPrimary,
                headerShadowVisible: false,
                headerTitleStyle: { fontWeight: '700' },
            }}
        >
            <Stack.Screen name="index" options={{ title: 'Customers' }} />
            <Stack.Screen name="[uuid]" options={{ title: 'Customer' }} />
        </Stack>
    );
}
