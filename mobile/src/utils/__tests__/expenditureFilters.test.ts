import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    filterExpendituresByCategory,
    periodLabel,
    periodStartDate,
} from '../expenditureFilters';

interface FakeExpenditure {
    local_uuid: string;
    category_uuid: string;
}

const sample: FakeExpenditure[] = [
    { local_uuid: 'a', category_uuid: 'fuel' },
    { local_uuid: 'b', category_uuid: 'tools' },
    { local_uuid: 'c', category_uuid: 'fuel' },
];

test('null category filter returns every expenditure untouched', () => {
    const result = filterExpendituresByCategory(sample, null);
    assert.equal(result.length, 3);
});

test('a category uuid filter returns only matching expenditures', () => {
    const result = filterExpendituresByCategory(sample, 'fuel');
    assert.deepEqual(result.map((e) => e.local_uuid), ['a', 'c']);
});

test('an empty list stays empty for any category filter', () => {
    assert.equal(filterExpendituresByCategory([], 'fuel').length, 0);
});

test('periodLabel returns the expected human labels', () => {
    assert.equal(periodLabel('today'), 'Today');
    assert.equal(periodLabel('week'), 'This week');
    assert.equal(periodLabel('month'), 'This month');
});

test('periodStartDate: "today" returns the anchor date itself', () => {
    const anchor = new Date(2026, 7, 27); // 2026-08-27 (local)
    assert.equal(periodStartDate('today', anchor), '2026-08-27');
});

test('periodStartDate: "week" returns 6 days before the anchor', () => {
    const anchor = new Date(2026, 7, 27); // 2026-08-27
    assert.equal(periodStartDate('week', anchor), '2026-08-21');
});

test('periodStartDate: "week" correctly crosses a month boundary', () => {
    const anchor = new Date(2026, 8, 2); // 2026-09-02
    assert.equal(periodStartDate('week', anchor), '2026-08-27');
});

test('periodStartDate: "month" returns the 1st of the anchor\'s month', () => {
    const anchor = new Date(2026, 7, 27); // 2026-08-27
    assert.equal(periodStartDate('month', anchor), '2026-08-01');
});
