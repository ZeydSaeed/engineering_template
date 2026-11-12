// RFC 6238 one-time passwords (SHA-1, 30 s, 6 digits), as Fortify checks
// them; the same algorithm as tests/load/lib/totp.js.
import { createHmac } from 'node:crypto';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(input: string): Buffer {
    const bytes: number[] = [];
    let bits = 0;
    let value = 0;

    for (const char of input.replace(/=+$/, '').toUpperCase()) {
        const index = ALPHABET.indexOf(char);

        if (index < 0) {
            throw new Error(`invalid base32 character: ${char}`);
        }

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            bytes.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(bytes);
}

export function totp(secret: string, at = Date.now()): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));

    const digest = createHmac('sha1', base32Decode(secret))
        .update(counter)
        .digest();
    const offset = digest[digest.length - 1] & 0x0f;

    const binary = digest.readUInt32BE(offset) & 0x7fffffff;

    return String(binary % 1_000_000).padStart(6, '0');
}
