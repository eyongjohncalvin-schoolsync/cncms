/**
 * The status pill on the phone's complaint desk (app/complaint-desk.tsx,
 * app/complaint/[uuid].tsx). Escalated outranks open/started, the same
 * order as the web's resources/tsx/lib/complaintState.ts. Kept free of
 * React Native so it is unit-testable.
 */
export type DeskTone = 'pending' | 'syncing' | 'rejected' | 'verified';

export function deskStatus(complaint: { status: string; escalated_at: string | null }): { label: string; tone: DeskTone } {
    if (complaint.status === 'resolved') {
        return { label: 'Resolved', tone: 'verified' };
    }

    if (complaint.escalated_at) {
        return { label: 'Escalated', tone: 'rejected' };
    }

    return complaint.status === 'in_progress' ? { label: 'Started', tone: 'syncing' } : { label: 'Open', tone: 'pending' };
}
