import { test } from 'node:test';
import assert from 'node:assert/strict';
import { compareVersions, needsUpdate } from '../appUpdate';

const latest = { latestVersion: '1.0.1', downloadUrl: 'https://cncms.tech/downloads/cncms-agent.apk' };

test('versions compare part by part, not as text', () => {
    assert.equal(compareVersions('1.0.10', '1.0.9'), 1);
    assert.equal(compareVersions('1.0.0', '1.0.1'), -1);
    assert.equal(compareVersions('1.0', '1.0.0'), 0);
    assert.equal(compareVersions('2.0.0', '1.9.9'), 1);
});

test('an older build is asked to update', () => {
    assert.equal(needsUpdate('1.0.0', latest), true);
});

test('the current or a newer build is left alone', () => {
    assert.equal(needsUpdate('1.0.1', latest), false);
    assert.equal(needsUpdate('1.0.2', latest), false);
});

test('nothing is shown without a server answer, a known version or a web link', () => {
    assert.equal(needsUpdate('1.0.0', null), false);
    assert.equal(needsUpdate(null, latest), false);
    assert.equal(needsUpdate('1.0.0', { ...latest, downloadUrl: 'intent://evil' }), false);
});
