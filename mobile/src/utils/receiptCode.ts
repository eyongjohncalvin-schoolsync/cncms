/**
 * The 8-character security code printed on every field receipt
 * (docs/plans/cash-controls.md) — the phone's half of
 * App\Support\ReceiptSecurityCode. The two MUST stay byte-for-byte identical:
 *
 *   code = Crockford-base32( first 40 bits of
 *            HMAC-SHA256(deviceSecret, "{NUMBER}|{customer_uuid}|{amount 2dp}|{issued_at unix}") )
 *
 * Implemented here in plain TypeScript (no native crypto module needed) so
 * it runs offline on any phone and under `node --test`; the vectors in
 * __tests__/receiptCode.test.ts were produced by the PHP implementation.
 */

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01,
    0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc,
    0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
    0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116, 0x1e376c08,
    0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

function rotr(value: number, bits: number): number {
    return (value >>> bits) | (value << (32 - bits));
}

/** SHA-256 of raw bytes. */
export function sha256(message: Uint8Array): Uint8Array {
    const hash = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);

    const bitLength = message.length * 8;
    const paddedLength = Math.ceil((message.length + 9) / 64) * 64;
    const padded = new Uint8Array(paddedLength);
    padded.set(message);
    padded[message.length] = 0x80;

    const view = new DataView(padded.buffer);
    view.setUint32(paddedLength - 8, Math.floor(bitLength / 0x100000000));
    view.setUint32(paddedLength - 4, bitLength >>> 0);

    const w = new Uint32Array(64);

    for (let offset = 0; offset < paddedLength; offset += 64) {
        for (let i = 0; i < 16; i++) {
            w[i] = view.getUint32(offset + i * 4);
        }

        for (let i = 16; i < 64; i++) {
            const s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
            const s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
        }

        let [a, b, c, d, e, f, g, h] = hash;

        for (let i = 0; i < 64; i++) {
            const s1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
            const ch = (e & f) ^ (~e & g);
            const t1 = (h + s1 + ch + K[i] + w[i]) >>> 0;
            const s0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const t2 = (s0 + maj) >>> 0;

            h = g;
            g = f;
            f = e;
            e = (d + t1) >>> 0;
            d = c;
            c = b;
            b = a;
            a = (t1 + t2) >>> 0;
        }

        hash[0] = (hash[0] + a) >>> 0;
        hash[1] = (hash[1] + b) >>> 0;
        hash[2] = (hash[2] + c) >>> 0;
        hash[3] = (hash[3] + d) >>> 0;
        hash[4] = (hash[4] + e) >>> 0;
        hash[5] = (hash[5] + f) >>> 0;
        hash[6] = (hash[6] + g) >>> 0;
        hash[7] = (hash[7] + h) >>> 0;
    }

    const out = new Uint8Array(32);
    const outView = new DataView(out.buffer);
    hash.forEach((word, i) => outView.setUint32(i * 4, word));

    return out;
}

export function hmacSha256(key: Uint8Array, message: Uint8Array): Uint8Array {
    const blockKey = new Uint8Array(64);
    blockKey.set(key.length > 64 ? sha256(key) : key);

    const inner = new Uint8Array(64 + message.length);
    const outer = new Uint8Array(64 + 32);

    for (let i = 0; i < 64; i++) {
        inner[i] = blockKey[i] ^ 0x36;
        outer[i] = blockKey[i] ^ 0x5c;
    }

    inner.set(message, 64);
    outer.set(sha256(inner), 64);

    return sha256(outer);
}

export function utf8(text: string): Uint8Array {
    return new TextEncoder().encode(text);
}

export function hexToBytes(hex: string): Uint8Array {
    const bytes = new Uint8Array(hex.length / 2);

    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    }

    return bytes;
}

/** Same as ReceiptSecurityCode::canonical(). */
export function canonicalReceipt(receiptNumber: string, customerUuid: string, amount: number, issuedAtUnix: number): string {
    return [receiptNumber.toUpperCase(), customerUuid.toLowerCase(), amount.toFixed(2), String(issuedAtUnix)].join('|');
}

/** Seconds since the epoch, floored — what PHP's getTimestamp() returns for the same ISO time. */
export function unixSeconds(iso: string): number {
    return Math.floor(Date.parse(iso) / 1000);
}

export function computeReceiptCode(hexSecret: string, canonical: string): string {
    const digest = hmacSha256(hexToBytes(hexSecret), utf8(canonical));

    let bits = '';
    for (let i = 0; i < 5; i++) {
        bits += digest[i].toString(2).padStart(8, '0');
    }

    let code = '';
    for (let i = 0; i < 40; i += 5) {
        code += ALPHABET[parseInt(bits.slice(i, i + 5), 2)];
    }

    return code;
}

/** "K7QF2MXA" → "K7QF-2MXA". */
export function formatReceiptCode(code: string): string {
    return `${code.slice(0, 4)}-${code.slice(4)}`;
}

/** The link the receipt's QR carries — PaymentReceiptLink::check(). */
export function receiptCheckUrl(checkUrlBase: string, receiptNumber: string, code: string): string {
    return `${checkUrlBase}${receiptNumber}?c=${code}`;
}
