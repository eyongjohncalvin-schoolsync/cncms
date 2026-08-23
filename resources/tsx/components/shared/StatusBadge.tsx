import { Badge } from '@/components/ui/Badge';
import type { CustomerStatus, VerificationStatus } from '@/types';

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
