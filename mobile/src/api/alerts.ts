import { apiClient } from './client';

/**
 * Quiet hours for phone alerts (docs/plans/mobile-manager-app.md, wave 2):
 * between start and end (Cameroon time, may cross midnight) urgent alerts
 * arrive silently; emergencies still ring. Both null = off.
 */
export interface QuietHours {
    quiet_start: string | null;
    quiet_end: string | null;
}

export async function fetchQuietHours(): Promise<QuietHours> {
    const { data } = await apiClient.get<{ data: QuietHours }>('/auth/alerts');

    return data.data;
}

export async function saveQuietHours(hours: QuietHours): Promise<QuietHours> {
    const { data } = await apiClient.put<{ data: QuietHours }>('/auth/alerts', hours);

    return data.data;
}
