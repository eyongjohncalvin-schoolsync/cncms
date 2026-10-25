import { test } from 'node:test';
import assert from 'node:assert/strict';
import { offerPriceFor } from '../offerPrice';

const offer = { price: '10000.00', branch_prices: { 'buea-uuid': '9500.00', 'kumba-uuid': '10000.00' } };

test("an offer is sold at the customer's branch price", () => {
    assert.equal(offerPriceFor(offer, 'buea-uuid'), 9500);
});

test("falls back to the agent's branch price when the branch is unknown", () => {
    assert.equal(offerPriceFor(offer, null), 10000);
    assert.equal(offerPriceFor(offer, 'unknown-uuid'), 10000);
    assert.equal(offerPriceFor({ price: '10000.00' }, 'buea-uuid'), 10000, 'older server: no branch_prices');
});
