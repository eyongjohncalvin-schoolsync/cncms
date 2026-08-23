export type Role = 'super' | 'admin' | 'manager' | 'agent' | 'worker';

export interface AuthUser {
    uuid: string;
    name: string;
    username: string;
    email: string;
    role: Role | null;
}

export interface PageProps {
    auth: {
        user: AuthUser | null;
    };
    flash: {
        success?: string | null;
        error?: string | null;
    };
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedResponse<T> {
    data: T[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

export type CustomerStatus = 'active' | 'passive' | 'disconnected' | 'suspended';
export type CustomerLevel = 'normal' | 'Vip' | 'Operator';

export interface Zone {
    uuid: string;
    name: string;
    town: string;
    customer_count?: number;
}

export interface Customer {
    uuid: string;
    name: string;
    phone: string | null;
    zone_uuid: string;
    zone_name: string;
    bill: string;
    others: string;
    level: CustomerLevel;
    status: CustomerStatus;
    location: string | null;
}

export type VerificationStatus = 'pending' | 'verified' | 'rejected';
export type PaymentFrequency = 'monthly' | 'yearly' | 'months';

export interface PaymentVerification {
    uuid: string;
    status: 'pending' | 'approved' | 'rejected';
    receipt_photo_url: string | null;
    momo_ref: string | null;
    momo_status: string | null;
    verified_by: string | null;
    verified_at: string | null;
    notes: string | null;
}

export interface Payment {
    uuid: string;
    customer_uuid: string;
    customer_name: string;
    zone_name?: string | null;
    amount: string;
    credit: string;
    frequency: PaymentFrequency;
    months: number | null;
    expiration_date: string | null;
    verification_status: VerificationStatus;
    recorded_offline: boolean;
    recorded_by_device?: string | null;
    created_at: string;
    processed_at: string | null;
    verification?: PaymentVerification | null;
}

export interface Manuscript {
    customer_uuid: string;
    customer_name: string;
    customer_code: string;
    phone: string | null;
    zone_name: string | null;
    level: CustomerLevel;
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    period: string;
    status: CustomerStatus;
}

export interface ManuscriptSummary {
    total_customers: number;
    total_bill: string;
    total_arrears: string;
    total_credit: string;
    total_collected: string;
    collection_rate: number;
}

export interface CustomerManuscriptSummary {
    uuid: string;
    bill: string;
    total_arrears: string;
    credit: string;
    total_bill: string;
    payment_expiration: string | null;
    period: string;
}

export interface CustomerRecentPayment {
    uuid: string;
    amount: string;
    credit: string;
    frequency: PaymentFrequency;
    verification_status: VerificationStatus;
    created_at: string;
}

export interface CustomerDetail extends Customer {
    description: string | null;
    created_at: string | null;
    manuscript: CustomerManuscriptSummary | null;
    recent_payments: CustomerRecentPayment[];
}

export interface Agent {
    uuid: string;
    name: string;
    location: string;
    phone: string;
    zone_uuid: string;
    zone_name: string | null;
    salary: string;
    email: string | null;
    dob: string | null;
    marital_status: 'yes' | 'no' | null;
    children: number | null;
    status: 'active' | 'inactive';
    last_sync_at: string | null;
}
