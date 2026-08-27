import * as SQLite from 'expo-sqlite';

/**
 * Local SQLite store. Table shapes match mobile-app-react-native.md §2
 * exactly: `customers` (read-only cache), `payments`/`expenditures`
 * (double as outbox + synced-history view, keyed by client-generated
 * `local_uuid`), `expense_categories` (refreshed from the plain REST
 * endpoint, not from pull()), `sync_meta` (KV — last_sync_at, device_id,
 * agent_zone_uuid).
 *
 * WAL mode per §2/§8 of the (superseded-in-part) offline-sync-strategy.md —
 * still the right call here for concurrent read (UI queries) + write
 * (SyncManager) access.
 */

const DB_NAME = 'cncms.db';

let dbPromise: Promise<SQLite.SQLiteDatabase> | null = null;
let migrationsDone = false;

export function getDatabase(): Promise<SQLite.SQLiteDatabase> {
    if (!dbPromise) {
        dbPromise = SQLite.openDatabaseAsync(DB_NAME);
    }

    return dbPromise;
}

const SCHEMA_SQL = `
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS customers (
    uuid            TEXT PRIMARY KEY NOT NULL,
    name            TEXT NOT NULL,
    phone           TEXT,
    bill            REAL NOT NULL DEFAULT 0,
    location        TEXT,
    level           TEXT,
    status          TEXT NOT NULL DEFAULT 'active',
    zone_uuid       TEXT,
    cached_at       TEXT NOT NULL,
    total_arrears   REAL,
    credit          REAL
);

CREATE INDEX IF NOT EXISTS idx_customers_zone ON customers(zone_uuid);
CREATE INDEX IF NOT EXISTS idx_customers_status ON customers(status);

CREATE TABLE IF NOT EXISTS payments (
    local_uuid              TEXT PRIMARY KEY NOT NULL,
    server_uuid             TEXT,
    customer_uuid           TEXT NOT NULL,
    amount                  REAL NOT NULL,
    credit                  REAL NOT NULL DEFAULT 0,
    frequency               TEXT NOT NULL,
    months                  INTEGER,
    verification_status     TEXT NOT NULL DEFAULT 'pending',
    rejection_reason        TEXT,
    receipt_local_uri       TEXT,
    receipt_server_path     TEXT,
    sync_status              TEXT NOT NULL DEFAULT 'queued',
    sync_error               TEXT,
    sync_attempts             INTEGER NOT NULL DEFAULT 0,
    created_at               TEXT NOT NULL,
    updated_at               TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_payments_sync_status ON payments(sync_status);
CREATE INDEX IF NOT EXISTS idx_payments_customer ON payments(customer_uuid);
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments(created_at);
CREATE INDEX IF NOT EXISTS idx_payments_server_uuid ON payments(server_uuid);

CREATE TABLE IF NOT EXISTS expenditures (
    local_uuid            TEXT PRIMARY KEY NOT NULL,
    server_uuid           TEXT,
    category_uuid         TEXT NOT NULL,
    amount                REAL NOT NULL,
    description           TEXT,
    spent_at              TEXT NOT NULL,
    notes                 TEXT,
    receipt_local_uri     TEXT,
    receipt_server_path   TEXT,
    sync_status            TEXT NOT NULL DEFAULT 'queued',
    sync_error             TEXT,
    sync_attempts           INTEGER NOT NULL DEFAULT 0,
    created_at             TEXT NOT NULL,
    updated_at             TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenditures_sync_status ON expenditures(sync_status);

CREATE TABLE IF NOT EXISTS expense_categories (
    uuid        TEXT PRIMARY KEY NOT NULL,
    name        TEXT NOT NULL,
    icon        TEXT,
    is_active   INTEGER NOT NULL DEFAULT 1,
    sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS sync_meta (
    key    TEXT PRIMARY KEY NOT NULL,
    value  TEXT NOT NULL
);

-- complaint-desk.md section 7 -- "Log a Complaint" doubles as the outbox
-- and local-history view, exact same shape convention as 'expenditures'
-- (local_uuid PK, server_uuid nullable until synced, sync_status/sync_error
-- /sync_attempts). No photo columns -- see LocalComplaint's doc comment in
-- src/types/db.ts. No priority/severity column -- urgent is the only
-- submitter-set flag, per the design doc's deliberate omission.
CREATE TABLE IF NOT EXISTS complaints (
    local_uuid       TEXT PRIMARY KEY NOT NULL,
    server_uuid      TEXT,
    category         TEXT NOT NULL,
    title            TEXT NOT NULL,
    description      TEXT,
    urgent           INTEGER NOT NULL DEFAULT 0,
    customer_uuid    TEXT,
    sync_status      TEXT NOT NULL DEFAULT 'queued',
    sync_error       TEXT,
    sync_attempts    INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_complaints_sync_status ON complaints(sync_status);
CREATE INDEX IF NOT EXISTS idx_complaints_created_at ON complaints(created_at);

-- in-app-notifications.md section 6 / complaint-desk.md section 7 -- a
-- read-only cache replaced/merged from pull()'s changes.notifications
-- block only, same convention as 'customers'. 'ack_pending' is the one
-- mobile-only addition (not present server-side) -- see LocalNotification's
-- doc comment in src/types/db.ts.
CREATE TABLE IF NOT EXISTS notifications (
    uuid             TEXT PRIMARY KEY NOT NULL,
    type             TEXT NOT NULL,
    severity         TEXT NOT NULL,
    title            TEXT NOT NULL,
    body             TEXT NOT NULL,
    link             TEXT,
    source_type      TEXT,
    source_uuid      TEXT,
    created_at       TEXT NOT NULL,
    read_at          TEXT,
    acknowledged_at  TEXT,
    ack_pending      INTEGER NOT NULL DEFAULT 0,
    cached_at        TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_notifications_severity ON notifications(severity);
CREATE INDEX IF NOT EXISTS idx_notifications_created_at ON notifications(created_at);
`;

/**
 * Columns added after a table's initial CREATE TABLE IF NOT EXISTS — that
 * guard only creates the table on a brand-new install, it never widens an
 * already-existing one, so a device that ran an older build needs an
 * explicit ALTER TABLE to pick new columns up. SQLite has no "ADD COLUMN IF
 * NOT EXISTS", so a "duplicate column name" failure (already applied) is
 * the expected, swallowed case; only that specific error is ignored —
 * anything else rethrows.
 */
const POST_INITIAL_COLUMNS: Array<{ table: string; ddl: string }> = [
    { table: 'customers', ddl: 'ALTER TABLE customers ADD COLUMN total_arrears REAL' },
    { table: 'customers', ddl: 'ALTER TABLE customers ADD COLUMN credit REAL' },
    { table: 'payments', ddl: 'ALTER TABLE payments ADD COLUMN receipt_local_uri TEXT' },
    { table: 'payments', ddl: 'ALTER TABLE payments ADD COLUMN receipt_server_path TEXT' },
];

/**
 * Create-tables-if-not-exist, then apply POST_INITIAL_COLUMNS. Safe to call
 * on every app launch — idempotent, and memoized within a single process
 * run via `migrationsDone` so repeated callers (multiple providers
 * mounting) don't re-run the exec unnecessarily.
 */
export async function runMigrations(): Promise<void> {
    if (migrationsDone) {
        return;
    }

    const db = await getDatabase();

    await db.execAsync(SCHEMA_SQL);

    for (const { ddl } of POST_INITIAL_COLUMNS) {
        try {
            await db.execAsync(ddl);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);

            if (!message.toLowerCase().includes('duplicate column')) {
                throw error;
            }
        }
    }

    migrationsDone = true;
}

/** Test/debug helper: lists actual table names present, to confirm the
 * schema really was created rather than assumed. Used during verification
 * and safe to keep for an in-app diagnostic if ever needed. */
export async function listTables(): Promise<string[]> {
    const db = await getDatabase();

    const rows = await db.getAllAsync<{ name: string }>(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'android_%'",
    );

    return rows.map((row) => row.name);
}
