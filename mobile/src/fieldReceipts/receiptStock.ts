import * as Device from 'expo-device';
import * as SecureStore from 'expo-secure-store';
import axios from 'axios';
import { fetchReceiptStock, registerFieldDevice } from '../api/fieldReceipts';
import { getCachedUserUuid } from '../auth/AuthContext';
import { replaceUnusedNumbers, takeNextNumber } from '../db/receiptNumbers';
import { canonicalReceipt, computeReceiptCode, unixSeconds } from '../utils/receiptCode';

/**
 * Field receipts (docs/plans/cash-controls.md, wave 3).
 *
 * The first time a collector syncs, this phone registers itself and is
 * given a signing secret (kept in the secure store, per collector). On every
 * sync it draws the system-issued receipt numbers it may print. When a
 * payment is recorded — online or not — it takes the next number and signs
 * the receipt's security code with that secret, so the customer can check
 * the receipt and the office can tell if anyone altered it.
 */

interface StoredDevice {
    device_uuid: string;
    secret: string;
    check_url_base: string;
}

export interface IssuedFieldReceipt {
    receipt_number: string;
    security_code: string;
    receipt_issued_at: string;
    check_url_base: string;
}

const STORE_OPTIONS: SecureStore.SecureStoreOptions = {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

function storeKey(userUuid: string): string {
    // SecureStore keys allow only [A-Za-z0-9._-].
    return `cncms_field_device_${userUuid.replace(/[^A-Za-z0-9._-]/g, '')}`;
}

async function readDevice(userUuid: string): Promise<StoredDevice | null> {
    const raw = await SecureStore.getItemAsync(storeKey(userUuid), STORE_OPTIONS);

    return raw ? (JSON.parse(raw) as StoredDevice) : null;
}

async function writeDevice(userUuid: string, device: StoredDevice): Promise<void> {
    await SecureStore.setItemAsync(storeKey(userUuid), JSON.stringify(device), STORE_OPTIONS);
}

async function clearDevice(userUuid: string): Promise<void> {
    await SecureStore.deleteItemAsync(storeKey(userUuid), STORE_OPTIONS);
}

async function register(userUuid: string): Promise<StoredDevice> {
    const name = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ') || 'Phone';
    const registered = await registerFieldDevice(name);
    const device = { device_uuid: registered.device_uuid, secret: registered.secret, check_url_base: registered.check_url_base };

    await writeDevice(userUuid, device);

    return device;
}

/**
 * Registers this phone if needed and refreshes its receipt numbers. Called
 * by SyncManager after every successful push/pull; best-effort — a failure
 * here never fails the sync (payments still record, just without a printed
 * receipt number until the next successful refresh).
 *
 * A phone the office revoked (or whose registration was lost) gets a 409;
 * it registers afresh, which shows up as a new phone on the Receipt Stock
 * page under the same collector.
 */
export async function syncReceiptStock(): Promise<void> {
    const userUuid = await getCachedUserUuid();

    if (!userUuid) {
        return;
    }

    let device = (await readDevice(userUuid)) ?? (await register(userUuid));

    let stock;

    try {
        stock = await fetchReceiptStock(device.device_uuid);
    } catch (error) {
        if (!axios.isAxiosError(error) || error.response?.status !== 409) {
            throw error;
        }

        await clearDevice(userUuid);
        device = await register(userUuid);
        stock = await fetchReceiptStock(device.device_uuid);
    }

    if (stock.check_url_base !== device.check_url_base) {
        await writeDevice(userUuid, { ...device, check_url_base: stock.check_url_base });
    }

    await replaceUnusedNumbers(userUuid, stock.numbers);
}

/**
 * Takes this collector's next receipt number for a payment being recorded
 * right now and signs it. Null when the phone isn't registered yet or has
 * run out of numbers — the payment is still recorded, and the office issues
 * the receipt when it verifies the payment, exactly as before.
 */
export async function issueFieldReceipt(paymentLocalUuid: string, customerUuid: string, amount: number): Promise<IssuedFieldReceipt | null> {
    const userUuid = await getCachedUserUuid();

    if (!userUuid) {
        return null;
    }

    const device = await readDevice(userUuid);

    if (!device) {
        return null;
    }

    const number = await takeNextNumber(userUuid, paymentLocalUuid);

    if (!number) {
        return null;
    }

    const issuedAt = new Date().toISOString();
    const code = computeReceiptCode(device.secret, canonicalReceipt(number, customerUuid, amount, unixSeconds(issuedAt)));

    return { receipt_number: number, security_code: code, receipt_issued_at: issuedAt, check_url_base: device.check_url_base };
}

export async function receiptCheckUrlBase(): Promise<string | null> {
    const userUuid = await getCachedUserUuid();

    return userUuid ? ((await readDevice(userUuid))?.check_url_base ?? null) : null;
}
