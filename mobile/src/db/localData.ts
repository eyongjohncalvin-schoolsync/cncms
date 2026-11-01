import { getDatabase } from './database';
import { getMeta, setMeta } from './syncMeta';

/**
 * Whose data this phone holds. Everything the app keeps offline —
 * customers, payments, complaints, the sync position, cached zones — is
 * one company's and one person's. It is stamped with `<company>:<person>`
 * at sign-in; anyone else signing in on the phone gets it wiped first, so
 * one company's records never show in another's account, and nothing
 * recorded by one person is ever sent under someone else's sign-in.
 */
const DATA_TABLES = ['customers', 'payments', 'expenditures', 'expense_categories', 'complaints', 'notifications', 'receipt_numbers', 'installations'];

/** Tables whose rows wait to be sent, and the states that mean "not sent yet". */
const OUTBOX_TABLES = ['payments', 'expenditures', 'complaints', 'installations'];
const UNSENT = `sync_status IN ('queued', 'failed', 'syncing')`;

export async function getDataOwner(): Promise<string | null> {
    return getMeta('data_owner');
}

export async function setDataOwner(owner: string): Promise<void> {
    await setMeta('data_owner', owner);
}

/** Records made on this phone and not yet sent (or a cancellation not yet told). */
export async function countUnsent(): Promise<number> {
    const db = await getDatabase();
    let total = 0;

    for (const table of OUTBOX_TABLES) {
        const row = await db.getFirstAsync<{ count: number }>(`SELECT COUNT(*) AS count FROM ${table} WHERE ${UNSENT}`);
        total += row?.count ?? 0;
    }

    const cancellations = await db.getFirstAsync<{ count: number }>(`SELECT COUNT(*) AS count FROM payments WHERE sync_status = 'cancelled' AND cancel_synced = 0 AND server_uuid IS NOT NULL`);

    return total + (cancellations?.count ?? 0);
}

/** Whether the phone holds any company data at all. */
export async function hasLocalData(): Promise<boolean> {
    const db = await getDatabase();

    for (const table of ['customers', 'payments']) {
        const row = await db.getFirstAsync<{ count: number }>(`SELECT COUNT(*) AS count FROM ${table}`);

        if ((row?.count ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Deletes every company record and the sync position; keeps only this
 * phone's own id. The next sync downloads the signed-in company's data
 * from scratch.
 */
export async function wipeLocalData(): Promise<void> {
    const db = await getDatabase();

    await db.withTransactionAsync(async () => {
        for (const table of DATA_TABLES) {
            await db.runAsync(`DELETE FROM ${table}`);
        }

        await db.runAsync(`DELETE FROM sync_meta WHERE key <> 'device_id'`);
    });
}
