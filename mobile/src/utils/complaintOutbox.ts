import type { ComplaintCategory, LocalComplaint } from '../types/db';

/**
 * Pure row-builder for the "Log a Complaint" local outbox write
 * (complaint-desk.md section 7) — split out from src/db/complaints.ts's
 * insertLocalComplaint() specifically so the shape of a freshly-queued
 * complaint row is unit-testable without expo-sqlite/expo-crypto (both
 * native modules unavailable under plain `node:test`), same "pure logic in
 * src/utils, DB glue in src/db" split as src/utils/validation.ts /
 * src/utils/paymentCalc.ts already establish for the payment/expenditure
 * screens. `localUuid`/`now` are injected rather than generated inside so
 * this stays a pure function — the real call site (insertLocalComplaint)
 * supplies generateUuid()/nowIso().
 */
export interface NewLocalComplaintInput {
    category: ComplaintCategory;
    title: string;
    description?: string | null;
    urgent: boolean;
    customer_uuid?: string | null;
}

export function buildLocalComplaintRow(input: NewLocalComplaintInput, localUuid: string, now: string): LocalComplaint {
    return {
        local_uuid: localUuid,
        server_uuid: null,
        category: input.category,
        title: input.title,
        description: input.description ?? null,
        // SQLite has no boolean column type — stored as 0/1, same
        // convention as LocalExpenseCategory.is_active.
        urgent: input.urgent ? 1 : 0,
        customer_uuid: input.category === 'customer' ? (input.customer_uuid ?? null) : null,
        // Every freshly-queued complaint starts exactly here — offline-safe
        // by construction, same "queued means saved, not merely attempted"
        // guarantee Record Payment/Record Expense already rely on
        // (mobile-app-react-native.md section 5).
        sync_status: 'queued',
        sync_error: null,
        sync_attempts: 0,
        created_at: now,
        updated_at: now,
    };
}
