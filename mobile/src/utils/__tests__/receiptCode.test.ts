import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canonicalReceipt, computeReceiptCode, formatReceiptCode, receiptCheckUrl, sha256, unixSeconds, utf8 } from '../receiptCode';
import { amountInEnglish, amountInFrench } from '../amountInWords';

// Produced by the server (App\Support\ReceiptSecurityCode / AmountInWords) —
// the phone must reproduce them exactly or every field receipt is refused.
const SECRET = '0f1e2d3c4b5a6978'.repeat(4);

const hex = (bytes: Uint8Array) => Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

test('sha256 matches the standard test vectors', () => {
    assert.equal(hex(sha256(utf8(''))), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    assert.equal(hex(sha256(utf8('abc'))), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

test('canonical string matches the server', () => {
    assert.equal(
        canonicalReceipt('rcp-2026-004017', 'ABCDEF01-2345-6789-abcd-ef0123456789', 2500.5, 1760963599),
        'RCP-2026-004017|abcdef01-2345-6789-abcd-ef0123456789|2500.50|1760963599',
    );
});

test('security codes match the server byte for byte', () => {
    assert.equal(
        computeReceiptCode(SECRET, 'RCP-2026-000123|0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b|10000.00|1760960000'),
        '2RCJRDGD',
    );
    assert.equal(
        computeReceiptCode(SECRET, canonicalReceipt('RCP-2026-004017', 'ABCDEF01-2345-6789-abcd-ef0123456789', 2500.5, 1760963599)),
        'S44C6HGS',
    );
});

test('changing the amount changes the code', () => {
    const genuine = computeReceiptCode(SECRET, canonicalReceipt('RCP-2026-000123', 'u', 10000, 1760960000));
    const altered = computeReceiptCode(SECRET, canonicalReceipt('RCP-2026-000123', 'u', 100000, 1760960000));

    assert.notEqual(genuine, altered);
});

test('unixSeconds floors milliseconds like PHP getTimestamp()', () => {
    assert.equal(unixSeconds('2025-10-20T11:33:20.999Z'), 1760960000);
});

test('formatting and the check link', () => {
    assert.equal(formatReceiptCode('2RCJRDGD'), '2RCJ-RDGD');
    assert.equal(receiptCheckUrl('https://cncms.tech/r/swecom/', 'RCP-2026-000123', '2RCJRDGD'), 'https://cncms.tech/r/swecom/RCP-2026-000123?c=2RCJRDGD');
});

test('amount in words matches the server', () => {
    const cases: Array<[number, string, string]> = [
        [1, 'Un franc CFA', 'One FCFA Only'],
        [21, 'Vingt et un francs CFA', 'Twenty-one FCFA Only'],
        [71, 'Soixante et onze francs CFA', 'Seventy-one FCFA Only'],
        [80, 'Quatre-vingts francs CFA', 'Eighty FCFA Only'],
        [81, 'Quatre-vingt-un francs CFA', 'Eighty-one FCFA Only'],
        [99, 'Quatre-vingt-dix-neuf francs CFA', 'Ninety-nine FCFA Only'],
        [101, 'Cent un francs CFA', 'One Hundred One FCFA Only'],
        [200, 'Deux cents francs CFA', 'Two Hundred FCFA Only'],
        [201, 'Deux cent un francs CFA', 'Two Hundred One FCFA Only'],
        [1000, 'Mille francs CFA', 'One Thousand FCFA Only'],
        [2500, 'Deux mille cinq cents francs CFA', 'Two Thousand Five Hundred FCFA Only'],
        [10000, 'Dix mille francs CFA', 'Ten Thousand FCFA Only'],
        [21000, 'Vingt et un mille francs CFA', 'Twenty-one Thousand FCFA Only'],
        [80000, 'Quatre-vingt mille francs CFA', 'Eighty Thousand FCFA Only'],
        [2500000, 'Deux millions cinq cent mille francs CFA', 'Two Million Five Hundred Thousand FCFA Only'],
    ];

    for (const [amount, french, english] of cases) {
        assert.equal(amountInFrench(amount), french, `french(${amount})`);
        assert.equal(amountInEnglish(amount), english, `english(${amount})`);
    }
});
