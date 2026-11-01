import { apiClient } from './client';

export interface CashTill {
    uuid: string;
    name: string;
    balance: string;
    /** The day this till counts next — yesterday's before today's. */
    day_to_close: string;
    /** What the book says the till held at the end of that day. */
    close_book: string;
    closed_today: boolean;
    can_close: boolean;
}

export interface CashHandover {
    uuid: string;
    number: string;
    collector_name: string | null;
    expected_cash: string;
    expected_mobile: string;
    expected_bank: string;
    bons_total: string;
    cash_to_hand_over: string;
    declared_cash: string;
    submitted_at: string | null;
    can_receive?: boolean;
}

export interface CashTransferItem {
    uuid: string;
    number: string;
    kind: 'handover' | 'deposit';
    from: string | null;
    to: string | null;
    sent_by_name: string | null;
    amount: string;
    slip_number: string | null;
    note: string | null;
    sent_at: string | null;
    confirmations: number;
    required_confirmations: number | null;
    can_accept: boolean;
    can_recall: boolean;
    can_confirm: boolean;
    can_refuse_deposit: boolean;
}

export interface CashShortageItem {
    /** `shortage` = an owned shortage; `remittance` = a handover received short. */
    type: 'shortage' | 'remittance';
    uuid: string;
    label: string;
    owner_name: string | null;
    amount: string;
    note: string | null;
    created_at: string | null;
    can_repay: boolean;
    can_write_off: boolean;
}

export interface CashAlert {
    key: string;
    severity: string;
    title: string;
    advice: string;
    items: { label: string; detail: string; amount: string | null }[];
}

export interface CashOverview {
    today: string;
    can: { receive: boolean; view_all: boolean };
    my_till: CashTill | null;
    to_receive: CashHandover[];
    transfers: CashTransferItem[];
    shortages: CashShortageItem[];
    alerts: CashAlert[];
}

export interface HandoverLine {
    id: number;
    customer_name: string | null;
    receipt_number: string | null;
    method: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
    amount: string;
    reference: string | null;
}

export interface HandoverDetail extends CashHandover {
    collector_note: string | null;
    lines: HandoverLine[];
    bons: { number: string; payee_name: string | null; amount: string }[];
    bank_accounts: { uuid: string; name: string }[];
    can_receive: boolean;
}

/**
 * Cash on the phone (docs/plans/mobile-manager-app.md, wave 4) — the same
 * rules as the web cash book. Online only: every call is a decision about
 * real money, never queued. Each action resolves to the server's own
 * sentence about what happened.
 */
export async function fetchCash(): Promise<CashOverview> {
    const { data } = await apiClient.get<{ data: CashOverview }>('/cash');

    return data.data;
}

export async function fetchHandover(uuid: string): Promise<HandoverDetail> {
    const { data } = await apiClient.get<{ data: HandoverDetail }>(`/cash/remittances/${uuid}`);

    return data.data;
}

async function act(path: string, body?: Record<string, unknown>): Promise<string> {
    const { data } = await apiClient.post<{ message: string }>(path, body);

    return data.message;
}

export function receiveHandover(uuid: string, receivedCash: string, confirmedLines: number[], bankAccount: string | null, note: string): Promise<string> {
    return act(`/cash/remittances/${uuid}/receive`, {
        received_cash: receivedCash,
        confirmed_lines: confirmedLines,
        bank_account: bankAccount,
        note: note || null,
    });
}

export function closeDay(tillUuid: string, counted: string, note: string): Promise<string> {
    return act(`/cash/accounts/${tillUuid}/close-day`, { counted, note: note || null });
}

export function acceptTransfer(uuid: string, counted: string, note: string): Promise<string> {
    return act(`/cash/transfers/${uuid}/accept`, { counted, note: note || null });
}

export function refuseTransfer(uuid: string, reason: string): Promise<string> {
    return act(`/cash/transfers/${uuid}/refuse`, { reason });
}

export function recallTransfer(uuid: string): Promise<string> {
    return act(`/cash/transfers/${uuid}/recall`);
}

export function confirmDeposit(uuid: string): Promise<string> {
    return act(`/cash/transfers/${uuid}/confirm`);
}

export function refuseDeposit(uuid: string, found: string, reason: string): Promise<string> {
    return act(`/cash/transfers/${uuid}/refuse-deposit`, { found, reason });
}

export function settleShortage(item: CashShortageItem, outcome: 'repaid' | 'written_off', note: string): Promise<string> {
    return item.type === 'remittance'
        ? act(`/cash/remittances/${item.uuid}/shortage`, { outcome, note: note || null })
        : act(`/cash/shortages/${item.uuid}/settle`, { outcome, note: note || null });
}
