import { apiClient } from './client';
import { getMeta, setMeta } from '../db/syncMeta';

/**
 * Prepayment offers on sale ("4 months for 10,000") at this agent's branch
 * price — GET /api/v1/payments/prepayment-offers
 * (App\Http\Controllers\Api\PaymentController::prepaymentOffers()). Cached
 * in sync_meta so an agent can sell one offline; picking one fixes the
 * amount and months on Record Payment, and the server re-checks it on sync.
 */
export interface PrepaymentOfferApi {
    uuid: string;
    name: string;
    months: number;
    /** Numeric string, this branch's price. */
    price: string;
}

export async function fetchPrepaymentOffers(): Promise<PrepaymentOfferApi[]> {
    const { data } = await apiClient.get<{ data: PrepaymentOfferApi[] }>('/payments/prepayment-offers');

    return data.data;
}

export async function getCachedPrepaymentOffers(): Promise<PrepaymentOfferApi[]> {
    const raw = await getMeta('cache_prepayment_offers');

    if (!raw) {
        return [];
    }

    try {
        return JSON.parse(raw) as PrepaymentOfferApi[];
    } catch {
        return [];
    }
}

/**
 * Fetches and caches them. A user who may not record payments (403) or an
 * older server (404) caches an empty list; network errors propagate.
 */
export async function refreshPrepaymentOffers(): Promise<PrepaymentOfferApi[]> {
    const offers = await fetchPrepaymentOffers().catch((error: unknown) => {
        const status = (error as { response?: { status?: number } })?.response?.status;

        if (status !== undefined) {
            return [];
        }

        throw error;
    });

    await setMeta('cache_prepayment_offers', JSON.stringify(offers));

    return offers;
}
