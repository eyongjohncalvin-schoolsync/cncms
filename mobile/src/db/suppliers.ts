import { getDatabase } from './database';
import { nowIso } from '../utils/format';
import type { LocalSupplier } from '../types/db';
import type { SupplierApi } from '../types/api';

/**
 * Offline cache of GET /resources/suppliers for Record Expense's supplier
 * picker. Same read-only-cache convention as `customers`: the server list
 * replaces the table on each refresh (so a supplier the office deactivated
 * disappears), and a supplier the worker just added online is upserted
 * straight away so it can be picked immediately.
 */
export async function replaceSuppliers(suppliers: SupplierApi[]): Promise<void> {
    const db = await getDatabase();
    const cachedAt = nowIso();

    await db.withTransactionAsync(async () => {
        await db.runAsync('DELETE FROM suppliers');

        for (const supplier of suppliers) {
            if (supplier.status === 'inactive') {
                continue;
            }

            await insertOrUpdate(supplier, cachedAt);
        }
    });
}

export async function upsertSupplier(supplier: SupplierApi): Promise<void> {
    await insertOrUpdate(supplier, nowIso());
}

async function insertOrUpdate(supplier: SupplierApi, cachedAt: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `INSERT INTO suppliers (uuid, name, phone, momo_number, location, status, cached_at)
         VALUES ($uuid, $name, $phone, $momo_number, $location, $status, $cached_at)
         ON CONFLICT(uuid) DO UPDATE SET
            name = excluded.name,
            phone = excluded.phone,
            momo_number = excluded.momo_number,
            location = excluded.location,
            status = excluded.status,
            cached_at = excluded.cached_at`,
        {
            $uuid: supplier.uuid,
            $name: supplier.name,
            $phone: supplier.phone,
            $momo_number: supplier.momo_number,
            $location: supplier.location,
            $status: supplier.status,
            $cached_at: cachedAt,
        },
    );
}

/** Active first, then pending ("awaiting approval"), each alphabetical. */
export async function getSuppliers(): Promise<LocalSupplier[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalSupplier>(
        `SELECT * FROM suppliers WHERE status != 'inactive'
         ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END, name COLLATE NOCASE ASC`,
    );
}
