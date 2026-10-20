import type { SQLiteDatabase } from 'expo-sqlite';
import { getDatabase } from './database';
import { nowIso } from '../utils/format';

/**
 * The system-issued receipt numbers this phone may print, per collector
 * (docs/plans/cash-controls.md). The server is the source of truth for
 * which numbers are still available; the phone only ever moves a number
 * from `unused` to `used` when it prints a receipt.
 */

/**
 * Merges the server's current list of unused numbers for this phone.
 * New numbers are added; a locally-unused number the server no longer lists
 * (cancelled or reassigned in the office) is dropped. A number the phone
 * already used is never touched — its payment may still be waiting to sync,
 * which is exactly why the server still lists it.
 */
export async function replaceUnusedNumbers(ownerUserUuid: string, serverNumbers: string[]): Promise<void> {
    const db = await getDatabase();
    const now = nowIso();
    const keep = new Set(serverNumbers);

    await db.withTransactionAsync(async () => {
        for (const number of serverNumbers) {
            await db.runAsync(
                `INSERT INTO receipt_numbers (number, owner_user_uuid, status, payment_local_uuid, updated_at)
                 VALUES (?, ?, 'unused', NULL, ?)
                 ON CONFLICT(number) DO NOTHING`,
                [number, ownerUserUuid, now],
            );
        }

        const localUnused = await db.getAllAsync<{ number: string }>(
            `SELECT number FROM receipt_numbers WHERE owner_user_uuid = ? AND status = 'unused'`,
            [ownerUserUuid],
        );

        for (const { number } of localUnused) {
            if (!keep.has(number)) {
                await db.runAsync('DELETE FROM receipt_numbers WHERE number = ?', [number]);
            }
        }
    });
}

/**
 * Takes the lowest unused number for this collector and marks it used by
 * the given payment, or returns null when the phone has none left (it must
 * sync to draw more). Runs inside the caller's transaction when given one.
 */
export async function takeNextNumber(ownerUserUuid: string, paymentLocalUuid: string, db?: SQLiteDatabase): Promise<string | null> {
    const database = db ?? (await getDatabase());

    const row = await database.getFirstAsync<{ number: string }>(
        `SELECT number FROM receipt_numbers WHERE owner_user_uuid = ? AND status = 'unused' ORDER BY number ASC LIMIT 1`,
        [ownerUserUuid],
    );

    if (!row) {
        return null;
    }

    await database.runAsync(
        `UPDATE receipt_numbers SET status = 'used', payment_local_uuid = ?, updated_at = ? WHERE number = ? AND status = 'unused'`,
        [paymentLocalUuid, nowIso(), row.number],
    );

    return row.number;
}

export async function countUnusedNumbers(ownerUserUuid: string): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM receipt_numbers WHERE owner_user_uuid = ? AND status = 'unused'`,
        [ownerUserUuid],
    );

    return row?.count ?? 0;
}
