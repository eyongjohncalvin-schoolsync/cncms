import { test } from 'node:test';
import assert from 'node:assert/strict';
import { glyphForCategoryIcon } from '../categoryIcons';

test('maps a known Tabler icon slug to its glyph', () => {
    assert.equal(glyphForCategoryIcon('ti-truck', 'Field Operations'), '🚚');
});

test('falls back to the category name\'s first letter for an unrecognized slug', () => {
    assert.equal(glyphForCategoryIcon('ti-something-new', 'zone bonus'), 'Z');
});

test('falls back to the first letter when icon is null', () => {
    assert.equal(glyphForCategoryIcon(null, 'Miscellaneous'), 'M');
});

test('falls back to "?" for an empty name and no icon', () => {
    assert.equal(glyphForCategoryIcon(null, ''), '?');
});
