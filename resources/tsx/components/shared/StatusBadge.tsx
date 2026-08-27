import { Badge } from '@/components/ui/Badge';
import { complaintVisualState, COMPLAINT_STATE_LABEL, COMPLAINT_STATE_TONE } from '@/lib/complaintState';
import type { ArrearsAdjustmentStatus, Complaint, CustomerStatus, VerificationStatus } from '@/types';

const customerToneMap: Record<CustomerStatus, 'green' | 'slate' | 'red' | 'yellow'> = {
    active: 'green',
    passive: 'slate',
    disconnected: 'red',
    suspended: 'yellow',
};

export function StatusBadge({ status }: { status: CustomerStatus }) {
    return <Badge tone={customerToneMap[status]}>{status}</Badge>;
}

const verificationToneMap: Record<VerificationStatus, 'green' | 'yellow' | 'red'> = {
    verified: 'green',
    pending: 'yellow',
    rejected: 'red',
};

const verificationLabelMap: Record<VerificationStatus, string> = {
    verified: 'Verified',
    pending: 'Pending',
    rejected: 'Rejected',
};

export function VerificationBadge({ status }: { status: VerificationStatus }) {
    return <Badge tone={verificationToneMap[status]}>{verificationLabelMap[status]}</Badge>;
}

export function RoleBadge({ role }: { role: string | null }) {
    if (!role) {
        return null;
    }

    return <Badge tone="blue">{role}</Badge>;
}

/**
 * The 5-state Badge treatment from references/complaint-desk.md section 6 —
 * zero new component work beyond this thin wrapper, reusing the existing
 * Badge tones exactly as the spec requires. The escalated state additionally
 * gets the `animate-pulse` dot, copied from Settings/Company.tsx's "Active"
 * indicator pattern (the one place the spec allows this treatment to be
 * reused elsewhere).
 */
export function ComplaintStatusBadge({ complaint }: { complaint: Pick<Complaint, 'status' | 'created_at' | 'escalated_at'> }) {
    const state = complaintVisualState(complaint);

    return (
        <Badge tone={COMPLAINT_STATE_TONE[state]}>
            {state === 'escalated' && <span className="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-red-600" aria-hidden="true" />}
            {COMPLAINT_STATE_LABEL[state]}
        </Badge>
    );
}

// Reuses the existing 5-tone Badge exactly as this feature's design doc
// specifies — no new visual component, just this thin status->tone/label
// wrapper, mirroring ComplaintStatusBadge's shape. 'pending' and
// 'pending_second_approval' share the same yellow "awaiting approval" tone
// (a second, more senior approval is still an approval still pending), with
// a distinct label so the two stages remain visible in a table.
const arrearsAdjustmentToneMap: Record<ArrearsAdjustmentStatus, 'yellow' | 'green' | 'red'> = {
    pending: 'yellow',
    pending_second_approval: 'yellow',
    approved: 'green',
    rejected: 'red',
};

const arrearsAdjustmentLabelMap: Record<ArrearsAdjustmentStatus, string> = {
    pending: 'Awaiting Approval',
    pending_second_approval: 'Awaiting 2nd Approval',
    approved: 'Applied',
    rejected: 'Rejected',
};

export function ArrearsAdjustmentStatusBadge({ status }: { status: ArrearsAdjustmentStatus }) {
    return <Badge tone={arrearsAdjustmentToneMap[status]}>{arrearsAdjustmentLabelMap[status]}</Badge>;
}
