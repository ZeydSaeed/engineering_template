// Checks the TOTP helper against the RFC 6238 test vectors, without any
// HTTP.  k6 run tests/load/selftest.js
import { check } from 'k6';
import { hotp, totp } from './lib/totp.js';

export const options = {
    vus: 1,
    iterations: 1,
    thresholds: { checks: ['rate==1'] },
};

export default function () {
    // RFC 6238 appendix B, SHA-1, secret "12345678901234567890" (base32 below).
    const secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    check(null, {
        'TOTP at 59 s is 94287082': () =>
            totp(secret, 59 * 1000, 8) === '94287082',
        'TOTP at 1111111109 s is 07081804': () =>
            totp(secret, 1111111109 * 1000, 8) === '07081804',
        'TOTP at 1234567890 s is 89005924': () =>
            totp(secret, 1234567890 * 1000, 8) === '89005924',
        'HOTP is zero-padded to 6 digits': () =>
            /^\d{6}$/.test(hotp(new ArrayBuffer(10), 1)),
    });
}
