import { test } from 'node:test';
import assert from 'node:assert/strict';
import { humanizeSyncError } from '../syncErrors';

test('passes through a plain Laravel validation message unchanged', () => {
    const message = 'The customer uuid field is required.';
    assert.equal(humanizeSyncError(message), message);
});

test('rephrases a raw SQLSTATE/exception message into plain language', () => {
    const raw = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint';
    const result = humanizeSyncError(raw);

    assert.notEqual(result, raw);
    assert.ok(result.toLowerCase().includes('server'));
});

test('rephrases a raw PHP fatal-error-style message', () => {
    const raw = 'Call to a member function create() on null';
    const result = humanizeSyncError(raw);

    assert.notEqual(result, raw);
});

test('handles a null/empty error with a calm fallback', () => {
    assert.ok(humanizeSyncError(null).length > 0);
    assert.ok(humanizeSyncError('').length > 0);
});

test('rephrases an overly long message rather than showing a wall of text', () => {
    const raw = 'x'.repeat(200);
    const result = humanizeSyncError(raw);
    assert.ok(result.length < raw.length);
});
