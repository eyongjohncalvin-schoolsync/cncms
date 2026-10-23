import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../ui/Card';
import { Badge, type BadgeTone } from '../ui/Badge';
import { getRecentInstallations } from '../../db/installations';
import { subscribeSyncState } from '../../sync/syncStore';
import { fieldInstallationStatus } from '../../utils/fieldInstallation';
import { formatFcfa, formatShortDate } from '../../utils/format';
import { humanizeSyncError } from '../../utils/syncErrors';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';
import type { LocalInstallation } from '../../types/db';

const TONE: Record<ReturnType<typeof fieldInstallationStatus>['tone'], BadgeTone> = {
    warning: 'pending',
    danger: 'rejected',
    success: 'verified',
    neutral: 'neutral',
};

/**
 * Customers this phone registered in the field (installation phase 3),
 * newest first: waiting to sync, not synced (why), Pending activation,
 * Activated, or Rejected with the office's reason. Shown on Sync status and
 * on the history tab. Renders nothing when there are none.
 */
export function FieldRegistrationsList({ title = 'Customers registered in the field', limit = 20 }: { title?: string; limit?: number }) {
    const [rows, setRows] = useState<LocalInstallation[]>([]);

    const refresh = useCallback(() => {
        void getRecentInstallations(limit).then(setRows);
    }, [limit]);

    useFocusEffect(
        useCallback(() => {
            refresh();
        }, [refresh]),
    );

    useEffect(() => subscribeSyncState(refresh), [refresh]);

    if (rows.length === 0) {
        return null;
    }

    return (
        <View style={styles.section}>
            <Text style={styles.sectionTitle}>{title}</Text>
            {rows.map((row) => {
                const status = fieldInstallationStatus({
                    ...row,
                    sync_error: row.sync_status === 'failed' ? humanizeSyncError(row.sync_error) : row.sync_error,
                });

                return (
                    <Card key={row.local_uuid} style={styles.card}>
                        <View style={styles.headerRow}>
                            <Text style={styles.name} numberOfLines={1}>
                                {row.name}
                            </Text>
                            <Badge label={row.sync_status === 'failed' ? 'Failed' : row.activation === 'rejected' ? 'Rejected' : status.label} tone={TONE[status.tone]} />
                        </View>
                        <Text style={styles.meta}>
                            {formatShortDate(row.created_at)} · collected {formatFcfa(row.collect_now)}
                            {row.possible_duplicate ? ' · flagged as a possible duplicate' : ''}
                        </Text>
                        {row.sync_status === 'failed' || row.activation === 'rejected' ? <Text style={styles.reason}>{status.label}</Text> : null}
                    </Card>
                );
            })}
        </View>
    );
}

const styles = StyleSheet.create({
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    card: { gap: spacing.xs },
    headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    name: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    meta: { fontSize: fontSize.xs, color: colors.textSecondary },
    reason: { fontSize: fontSize.sm, color: colors.status.errorFg },
});
