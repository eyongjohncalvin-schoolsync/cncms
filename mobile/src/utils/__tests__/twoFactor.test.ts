import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    describeUserAgent,
    formatSetupKey,
    isCompleteOneTimeCode,
    isTwoFactorChallenge,
    normalizeOneTimeCode,
} from '../twoFactor';

test('isTwoFactorChallenge: a challenge response is recognised', () => {
    assert.equal(isTwoFactorChallenge({ two_factor_required: true, challenge_token: 'abc' }), true);
});

test('isTwoFactorChallenge: a token response, or a challenge without a token, is not', () => {
    assert.equal(isTwoFactorChallenge({ token: 'x' }), false);
    assert.equal(isTwoFactorChallenge({ two_factor_required: true, challenge_token: '' }), false);
    assert.equal(isTwoFactorChallenge({ two_factor_required: false, challenge_token: 'abc' }), false);
});

test('normalizeOneTimeCode: authenticator/email codes keep 6 digits only', () => {
    assert.equal(normalizeOneTimeCode(' 123 456 ', 'totp'), '123456');
    assert.equal(normalizeOneTimeCode('12-34-56-78', 'email'), '123456');
    assert.equal(normalizeOneTimeCode('abc', 'totp'), '');
});

test('normalizeOneTimeCode: recovery codes are upper-cased with the dash after 5 characters', () => {
    assert.equal(normalizeOneTimeCode('abcde fghjk', 'recovery'), 'ABCDE-FGHJK');
    assert.equal(normalizeOneTimeCode('ab', 'recovery'), 'AB');
    assert.equal(normalizeOneTimeCode('ABCDE-FGHJK-EXTRA', 'recovery'), 'ABCDE-FGHJK');
});

test('isCompleteOneTimeCode: full-length codes only', () => {
    assert.equal(isCompleteOneTimeCode('123456', 'totp'), true);
    assert.equal(isCompleteOneTimeCode('12345', 'email'), false);
    assert.equal(isCompleteOneTimeCode('ABCDE-FGHJK', 'recovery'), true);
    assert.equal(isCompleteOneTimeCode('ABCDEFGHJK', 'recovery'), true);
    assert.equal(isCompleteOneTimeCode('ABCDE-FGH', 'recovery'), false);
});

test('formatSetupKey: groups of four', () => {
    assert.equal(formatSetupKey('ABCDEFGHIJKLMN'), 'ABCD EFGH IJKL MN');
    assert.equal(formatSetupKey('ABCD EFGH'), 'ABCD EFGH');
});

test('describeUserAgent: common browsers and systems, with fallbacks', () => {
    assert.equal(describeUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'), 'Chrome · Windows');
    assert.equal(describeUserAgent('Mozilla/5.0 (Linux; Android 14) Firefox/131.0'), 'Firefox · Android');
    assert.equal(describeUserAgent(null), 'Unknown browser');
    assert.equal(describeUserAgent('curl/8.0'), 'curl/8.0');
});
