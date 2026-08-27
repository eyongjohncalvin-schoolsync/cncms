import type { Complaint } from '@/types';

export type ComplaintVisualState = 'new' | 'in_progress' | 'approaching_deadline' | 'escalated' | 'resolved';

/**
 * The 5-state Badge treatment from references/complaint-desk.md section 6.
 * This is a COMPUTED visual state, not a literal column — `status` alone
 * only has 3 values (open/in_progress/resolved; escalation is deliberately
 * NOT a status value, see Complaint's backend class doc), so "approaching
 * 48h" and "escalated" are derived here from `created_at`/`escalated_at`.
 *
 * The 36h "approaching" threshold matches
 * App\Repositories\Eloquent\ComplaintRepository::dashboardCounts()'s
 * `approaching_deadline` count so the badge a user sees on a row always
 * agrees with the dashboard's tally of the same thing.
 */
const APPROACHING_THRESHOLD_HOURS = 36;

export function complaintVisualState(complaint: Pick<Complaint, 'status' | 'created_at' | 'escalated_at'>): ComplaintVisualState {
    if (complaint.status === 'resolved') {
        return 'resolved';
    }

    // Escalated is a time-based FACT (escalated_at set), independent of
    // status — a complaint can be in_progress AND escalated at once, per
    // the spec. It always wins over "approaching" once set.
    if (complaint.escalated_at) {
        return 'escalated';
    }

    const hoursOld = (Date.now() - new Date(complaint.created_at).getTime()) / (1000 * 60 * 60);

    if (hoursOld >= APPROACHING_THRESHOLD_HOURS) {
        return 'approaching_deadline';
    }

    return complaint.status === 'in_progress' ? 'in_progress' : 'new';
}

export const COMPLAINT_STATE_TONE: Record<ComplaintVisualState, 'slate' | 'blue' | 'yellow' | 'red' | 'green'> = {
    new: 'slate',
    in_progress: 'blue',
    approaching_deadline: 'yellow',
    escalated: 'red',
    resolved: 'green',
};

export const COMPLAINT_STATE_LABEL: Record<ComplaintVisualState, string> = {
    new: 'New',
    in_progress: 'In Progress',
    approaching_deadline: 'Approaching Deadline',
    escalated: 'Escalated',
    resolved: 'Resolved',
};
