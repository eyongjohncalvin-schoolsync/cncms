import { useState } from 'react';
import { Image, StyleSheet } from 'react-native';
import { API_BASE_URL } from '../../api/config';
import { getCurrentToken } from '../../auth/tokenState';
import { colors } from '../../theme/colors';

/**
 * The signed-in user's own company logo (GET /company/logo, server side
 * App\Http\Controllers\CompanyLogoController), loaded with the Bearer token.
 * Renders nothing when the company has no logo or the phone is offline, so
 * it never leaves a broken-image box. The date in the URL refreshes the
 * image cache daily, so a newly uploaded logo shows up by the next day.
 */
export function CompanyLogo({ size = 44 }: { size?: number }) {
    const [failed, setFailed] = useState(false);
    const token = getCurrentToken();

    if (failed || !token) {
        return null;
    }

    const day = new Date().toISOString().slice(0, 10);

    return (
        <Image
            accessibilityLabel="Company logo"
            source={{ uri: `${API_BASE_URL}/company/logo?d=${day}`, headers: { Authorization: `Bearer ${token}` } }}
            onError={() => setFailed(true)}
            resizeMode="contain"
            style={[styles.logo, { width: size, height: size, borderRadius: size / 4 }]}
        />
    );
}

const styles = StyleSheet.create({
    logo: {
        backgroundColor: colors.surface,
        borderWidth: StyleSheet.hairlineWidth,
        borderColor: colors.border,
    },
});
