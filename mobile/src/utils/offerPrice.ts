/**
 * A prepayment offer's price for one customer: the price at the customer's
 * branch — what the server charges (PaymentService::applyPrepaymentOffer())
 * — else this agent's branch price (older servers, or a customer cached
 * before the phone knew their branch).
 */
export function offerPriceFor(offer: { price: string; branch_prices?: Record<string, string> }, branchUuid: string | null | undefined): number {
    const atBranch = branchUuid ? offer.branch_prices?.[branchUuid] : undefined;

    return Number(atBranch ?? offer.price);
}
