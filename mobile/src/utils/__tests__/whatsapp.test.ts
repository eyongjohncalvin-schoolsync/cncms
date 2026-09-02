import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildWhatsAppBillLink, normalizeCameroonPhoneForWhatsapp } from '../whatsapp';

test('normalizeCameroonPhoneForWhatsapp: 9-digit local number gets the 237 prefix', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('677440670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: already-international 12-digit number passes through', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('237677440670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: leading 0 (trunk prefix) is stripped before adding 237', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('0677440670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: messy formatting (parens/spaces/dashes) is stripped', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('(67) 744-0670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: a leading + is stripped like any other non-digit', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('+237677440670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: a 00237 international-access prefix is normalized', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('00237677440670'), '237677440670');
});

test('normalizeCameroonPhoneForWhatsapp: null/undefined/empty/whitespace-only return null, not a guess', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp(null), null);
    assert.equal(normalizeCameroonPhoneForWhatsapp(undefined), null);
    assert.equal(normalizeCameroonPhoneForWhatsapp(''), null);
    assert.equal(normalizeCameroonPhoneForWhatsapp('   '), null);
});

test('normalizeCameroonPhoneForWhatsapp: wrong digit count (too short/long, not a 237-prefixed 12) returns null', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('12345'), null);
    assert.equal(normalizeCameroonPhoneForWhatsapp('67744067'), null); // 8 digits
    assert.equal(normalizeCameroonPhoneForWhatsapp('6774406700'), null); // 10 digits
    assert.equal(normalizeCameroonPhoneForWhatsapp('123456789012345'), null);
});

test('normalizeCameroonPhoneForWhatsapp: letters-only input returns null', () => {
    assert.equal(normalizeCameroonPhoneForWhatsapp('not-a-phone'), null);
});

test('buildWhatsAppBillLink: builds a valid wa.me URL with URL-encoded message text', () => {
    const link = buildWhatsAppBillLink('237677440670', 'Hello Ashu Peter, your bill is 7,500 FCFA.');
    assert.equal(
        link,
        'https://wa.me/237677440670?text=Hello%20Ashu%20Peter%2C%20your%20bill%20is%207%2C500%20FCFA.',
    );
});

test('buildWhatsAppBillLink: encodes special characters (accents, ampersands) safely', () => {
    const link = buildWhatsAppBillLink('237677440670', 'Montant & solde: 5,000 FCFA — à payer');
    assert.ok(link?.startsWith('https://wa.me/237677440670?text='));
    // Round-trips back to the original message when decoded.
    const encoded = link!.split('?text=')[1];
    assert.equal(decodeURIComponent(encoded), 'Montant & solde: 5,000 FCFA — à payer');
});

test('buildWhatsAppBillLink: returns null when phone is missing', () => {
    assert.equal(buildWhatsAppBillLink(null, 'Hello'), null);
    assert.equal(buildWhatsAppBillLink(undefined, 'Hello'), null);
    assert.equal(buildWhatsAppBillLink('', 'Hello'), null);
});

test('buildWhatsAppBillLink: returns null when message is missing', () => {
    assert.equal(buildWhatsAppBillLink('237677440670', null), null);
    assert.equal(buildWhatsAppBillLink('237677440670', undefined), null);
    assert.equal(buildWhatsAppBillLink('237677440670', ''), null);
});

test('buildWhatsAppBillLink: returns null when both are missing', () => {
    assert.equal(buildWhatsAppBillLink(null, null), null);
});
