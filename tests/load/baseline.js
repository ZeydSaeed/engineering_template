// Baseline load: anonymous visitors on public pages and signed-in staff
// moving between pages. Staff sign in for real (password, then the TOTP code
// of their own synthetic secret). Reads Server-Timing to split app and
// database time. Local hosts only (lib/guard.js).
//
//   k6 run -e VISITOR_VUS=30 -e STAFF_VUS=20 -e HOLD=2m tests/load/baseline.js
//
// Users come from storage/app/private/perf/users.csv (PerformanceSeeder).
import { check, fail, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { baseUrl } from './lib/guard.js';
import { totp } from './lib/totp.js';

const BASE = baseUrl();
const PASSWORD = 'Perf-Passw0rd!long';
const HOLD = __ENV.HOLD || '2m';
const visitorVus = Number(__ENV.VISITOR_VUS || 30);
const staffVus = Number(__ENV.STAFF_VUS || 20);

const users = new SharedArray('users', () =>
    open(__ENV.USERS_CSV || '../../storage/app/private/perf/users.csv')
        .trim()
        .split('\n')
        .slice(1)
        .map((line) => {
            const [email, secret] = line.trim().split(',');
            return { email, secret };
        }),
);

const appTime = new Trend('server_app_ms', true);
const dbTime = new Trend('server_db_ms', true);
const dbQueries = new Trend('server_db_queries');
const signInFailures = new Counter('sign_in_failures');

const stages = (vus) => [
    { duration: '30s', target: vus },
    { duration: HOLD, target: vus },
    { duration: '15s', target: 0 },
];

export const options = {
    scenarios: {
        visitors: {
            executor: 'ramping-vus',
            exec: 'visitor',
            stages: stages(visitorVus),
        },
        staff: {
            executor: 'ramping-vus',
            exec: 'staff',
            stages: stages(staffVus),
        },
    },
    thresholds: {
        http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: true }],
        checks: ['rate>0.99'],
        sign_in_failures: ['count==0'],
    },
};

function recordTiming(response) {
    const header = response.headers['Server-Timing'] || '';
    const app = /app;dur=([\d.]+)/.exec(header);
    const db = /db;dur=([\d.]+);desc="(\d+) queries"/.exec(header);
    const tags = { page: response.request.url.replace(BASE, '') || '/' };

    if (app) appTime.add(Number(app[1]), tags);
    if (db) {
        dbTime.add(Number(db[1]), tags);
        dbQueries.add(Number(db[2]), tags);
    }
}

function get(path, type) {
    const response = http.get(`${BASE}${path}`, { tags: { type, page: path } });
    recordTiming(response);
    check(response, { [`${path} 200`]: (r) => r.status === 200 });
    return response;
}

function xsrf() {
    const cookie = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return cookie ? decodeURIComponent(cookie[0]) : '';
}

function post(path, body) {
    const response = http.post(`${BASE}${path}`, body, {
        headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' },
        tags: { type: 'auth', page: path },
    });
    recordTiming(response);
    return response;
}

// Warm-up (`k6 run --vus 1 --iterations 1`): every page once, in sequence.
export default function () {
    visitor();
    staff();
}

export function visitor() {
    get('/', 'public');
    sleep(1 + Math.random() * 2);
    get('/login', 'public');
    sleep(1 + Math.random() * 2);
}

let signedIn = false;

function signIn() {
    if (users.length < staffVus) {
        fail(
            `users.csv has ${users.length} users; STAFF_VUS=${staffVus} needs one each`,
        );
    }

    const user = users[(__VU - 1) % users.length];
    get('/login', 'auth');

    const password = post('/login', { email: user.email, password: PASSWORD });
    if (!password.url.includes('/two-factor-challenge')) {
        signInFailures.add(1, { step: 'password' });
        return false;
    }

    const code = post('/two-factor-challenge', { code: totp(user.secret) });
    const ok = code.url.endsWith('/dashboard');
    if (!ok) signInFailures.add(1, { step: 'two-factor' });

    return ok;
}

export function staff() {
    if (!signedIn) {
        signedIn = signIn();
        if (!signedIn) {
            sleep(5);
            return;
        }
    }

    get('/dashboard', 'read');
    sleep(2 + Math.random() * 3);
    get('/settings/profile', 'read');
    sleep(2 + Math.random() * 3);
    get('/settings/appearance', 'read');
    sleep(2 + Math.random() * 3);
}
