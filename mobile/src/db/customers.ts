import { getDatabase } from './database';
import type { LocalCustomer } from '../types/db';
import type { SyncPullCustomer } from '../types/api';
import { nowIso } from '../utils/format';

/**
 * `customers` is a read-only cache, fully replaced/merged from pull()'s
 * changes.customers.upserted only — never locally edited. See
 * mobile-app-react-native.md §2.
 */
export async function upsertCustomers(customers: SyncPullCustomer[]): Promise<void> {
    if (customers.length === 0) {
        return;
    }

    const db = await getDatabase();
    const cachedAt = nowIso();

    await db.withTransactionAsync(async () => {
        for (const customer of customers) {
            await db.runAsync(
                `INSERT INTO customers (uuid, name, phone, bill, location, level, status, zone_uuid, cached_at, total_arrears, credit)
                 VALUES ($uuid, $name, $phone, $bill, $location, $level, $status, $zone_uuid, $cached_at, $total_arrears, $credit)
                 ON CONFLICT(uuid) DO UPDATE SET
                    name = excluded.name,
                    phone = excluded.phone,
                    bill = excluded.bill,
                    location = excluded.location,
                    level = excluded.level,
                    status = excluded.status,
                    zone_uuid = excluded.zone_uuid,
                    cached_at = excluded.cached_at,
                    total_arrears = excluded.total_arrears,
                    credit = excluded.credit`,
                {
                    $uuid: customer.uuid,
                    $name: customer.name,
                    $phone: customer.phone,
                    $bill: Number(customer.bill) || 0,
                    $location: customer.location,
                    $level: customer.level,
                    $status: customer.status,
                    $zone_uuid: customer.zone_uuid,
                    $cached_at: cachedAt,
                    $total_arrears: customer.total_arrears === null || customer.total_arrears === undefined ? null : Number(customer.total_arrears),
                    $credit: customer.credit === null || customer.credit === undefined ? null : Number(customer.credit),
                },
            );
        }
    });
}

/**
 * Evicts customers the server has archived (soft-deleted) — pull()'s
 * `changes.customers.deleted` (App\Services\SyncService::deletedCustomers()).
 * The `customers` cache is read-only and rebuilt from the server, so a
 * straight delete is safe: if the customer is later restored server-side,
 * the next pull re-adds them via `upserted`. Any locally-queued payment
 * that referenced them is untouched — it still syncs on its own uuid.
 */
export async function deleteCustomers(uuids: string[]): Promise<void> {
    if (uuids.length === 0) {
        return;
    }

    const db = await getDatabase();
    const placeholders = uuids.map(() => '?').join(', ');

    await db.runAsync(`DELETE FROM customers WHERE uuid IN (${placeholders})`, uuids);
}

export async function getAllCustomers(): Promise<LocalCustomer[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalCustomer>('SELECT * FROM customers ORDER BY name ASC');
}

export async function getCustomerByUuid(uuid: string): Promise<LocalCustomer | null> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<LocalCustomer>('SELECT * FROM customers WHERE uuid = ?', [uuid]);

    return row ?? null;
}

export async function countCustomers(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>('SELECT COUNT(*) as count FROM customers');

    return row?.count ?? 0;
}

export interface ZoneSnapshot {
    owesMoneyCount: number;
    arrearsTotal: number;
    disconnectedCount: number;
}

/**
 * Powers Home's zone-snapshot tiles — mobile-app-react-native.md §4 calls
 * for "a 3-tile zone snapshot (arrears count/total, disconnected-but-
 * visitable count)" that wasn't actually wired up on Home before the
 * 2026-08-27 pass (see that file's dated addendum for the full rationale).
 * One aggregate query rather than three separate ones, so this stays
 * instant and offline like every other Home stat — all from the local
 * cache, never a live call.
 */
export async function getZoneSnapshot(): Promise<ZoneSnapshot> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{
        owes_money_count: number;
        arrears_total: number;
        disconnected_count: number;
    }>(
        `SELECT
            COUNT(CASE WHEN total_arrears > 0 THEN 1 END) as owes_money_count,
            COALESCE(SUM(CASE WHEN total_arrears > 0 THEN total_arrears ELSE 0 END), 0) as arrears_total,
            COUNT(CASE WHEN status = 'disconnected' THEN 1 END) as disconnected_count
         FROM customers`,
    );

    return {
        owesMoneyCount: row?.owes_money_count ?? 0,
        arrearsTotal: row?.arrears_total ?? 0,
        disconnectedCount: row?.disconnected_count ?? 0,
    };
}
