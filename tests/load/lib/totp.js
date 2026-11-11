// RFC 6238 time-based one-time passwords (SHA-1, 30 s, 6 digits), as
// Google Authenticator and Laravel Fortify use them.
import crypto from 'k6/crypto';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

export function base32Decode(input) {
    const clean = input.replace(/=+$/, '').toUpperCase();
    const bytes = [];
    let bits = 0;
    let value = 0;

    for (const char of clean) {
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

    return new Uint8Array(bytes).buffer;
}

export function hotp(key, counter, digits = 6) {
    const message = new Uint8Array(8);
    let rest = counter;
    for (let i = 7; i >= 0; i--) {
        message[i] = rest % 256;
        rest = Math.floor(rest / 256);
    }

    const hmac = crypto.createHMAC('sha1', key);
    hmac.update(message.buffer);
    const digest = new Uint8Array(hmac.digest('binary'));
    const offset = digest[digest.length - 1] & 0x0f;
    const binary =
        ((digest[offset] & 0x7f) << 24) |
        (digest[offset + 1] << 16) |
        (digest[offset + 2] << 8) |
        digest[offset + 3];

    return String(binary % 10 ** digits).padStart(digits, '0');
}

export function totp(base32Secret, at = Date.now(), digits = 6) {
    return hotp(base32Decode(base32Secret), Math.floor(at / 1000 / 30), digits);
}
