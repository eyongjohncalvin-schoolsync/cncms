import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatRelativeTime, toDateOnly, todayDateOnly } from '../format';

test('toDateOnly formats using local calendar fields, not a UTC slice', () => {
    // 23:30 local time on Aug 23 — a naive `toISOString().slice(0,10)` in a
    // negative-UTC-offset timezone could roll this to Aug 24. toDateOnly
    // must not do that.
    const date = new Date(2026, 7, 23, 23, 30, 0); // month is 0-indexed: 7 = August
    assert.equal(toDateOnly(date), '2026-08-23');
});

test('toDateOnly pads single-digit months/days', () => {
    const date = new Date(2026, 0, 5); // Jan 5
    assert.equal(toDateOnly(date), '2026-01-05');
});

test('todayDateOnly matches toDateOnly(new Date())', () => {
    assert.equal(todayDateOnly(), toDateOnly(new Date()));
});

test('formatRelativeTime: null/invalid input reads as "Never synced"', () => {
    assert.equal(formatRelativeTime(null), 'Never synced');
    assert.equal(formatRelativeTime('not-a-date'), 'Never synced');
});

test('formatRelativeTime: seconds/minutes/hours/days buckets', () => {
    const now = new Date('2026-08-23T12:00:00.000Z');

    assert.equal(formatRelativeTime('2026-08-23T11:59:58.000Z', now), 'Just now');
    assert.equal(formatRelativeTime('2026-08-23T11:58:00.000Z', now), '2 min ago');
    assert.equal(formatRelativeTime('2026-08-23T10:00:00.000Z', now), '2 hrs ago');
    assert.equal(formatRelativeTime('2026-08-21T12:00:00.000Z', now), '2 days ago');
});
