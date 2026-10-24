import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isFinalUploadFailure, receiptPhotoResize, withJitter } from '../syncTuning';

test('a landscape photo is resized by its width', () => {
    assert.deepEqual(receiptPhotoResize(4000, 3000), { width: 1280 });
});

test('a portrait photo is resized by its height', () => {
    assert.deepEqual(receiptPhotoResize(3000, 4000), { height: 1280 });
});

test('a square photo is resized by its width', () => {
    assert.deepEqual(receiptPhotoResize(2000, 2000), { width: 1280 });
});

test('a photo that already fits is left alone', () => {
    assert.equal(receiptPhotoResize(1280, 960), null);
    assert.equal(receiptPhotoResize(800, 600), null);
});

test('unknown dimensions are left alone', () => {
    assert.equal(receiptPhotoResize(0, 0), null);
    assert.equal(receiptPhotoResize(Number.NaN, 3000), null);
});

test('jitter stays within plus or minus 20 percent', () => {
    assert.equal(withJitter(10_000, () => 0), 8_000);
    assert.equal(withJitter(10_000, () => 0.5), 10_000);
    assert.equal(withJitter(10_000, () => 0.999999), 12_000);
});

test('413 and 422 are final upload failures', () => {
    assert.equal(isFinalUploadFailure(413), true);
    assert.equal(isFinalUploadFailure(422), true);
});

test('timeouts, network errors and server errors are retried', () => {
    assert.equal(isFinalUploadFailure(undefined), false);
    assert.equal(isFinalUploadFailure(null), false);
    assert.equal(isFinalUploadFailure(500), false);
    assert.equal(isFinalUploadFailure(503), false);
    assert.equal(isFinalUploadFailure(408), false);
    assert.equal(isFinalUploadFailure(403), false);
});
