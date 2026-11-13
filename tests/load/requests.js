// Load on the Requests module (ADR-0033): requesters draft and submit
// requests while approvers work through the queue and decide. Everyone signs
// in for real (password, then the TOTP code of their own synthetic secret).
// Reads Server-Timing to split app and database time. Local hosts only
// (lib/guard.js).
//
//   k6 run -e STAFF_VUS=20 -e APPROVER_VUS=5 -e HOLD=2m tests/load/requests.js
//
// Users come from storage/app/private/perf/users.csv (PerformanceSeeder);
// perf-51 to perf-60 are approvers and perf-61 onwards requesters
// (RequestsPerformanceSeeder).
import { check, fail, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { baseUrl } from './lib/guard.js';
import { totp } from './lib/totp.js';

const BASE = baseUrl();
const PASSWORD = 'Perf-Passw0rd!long';
const HOLD = __ENV.HOLD || '2m';
const requesterVus = Number(__ENV.STAFF_VUS || 20);
const approverVus = Number(__ENV.APPROVER_VUS || 5);
const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/;

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
const created = new Counter('requests_created');
const submitted = new Counter('requests_submitted');
const decided = new Counter('requests_decided');
// Two approvers chose the same request; the second is told it is already
// decided. Expected under load, and not an error.
const alreadyDecided = new Counter('requests_already_decided');

const stages = (vus) => [
    { duration: '30s', target: vus },
    { duration: HOLD, target: vus },
    { duration: '15s', target: 0 },
];

export const options = {
    // Keep each VU's session between iterations: k6 empties the cookie jar
    // after every iteration by default, which silently signs staff out.
    noCookiesReset: true,
    scenarios: {
        requesters: {
            executor: 'ramping-vus',
            exec: 'requester',
            stages: stages(requesterVus),
        },
        approvers: {
            executor: 'ramping-vus',
            exec: 'approver',
            stages: stages(approverVus),
        },
    },
    thresholds: {
        http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: true }],
        checks: ['rate>0.99'],
        sign_in_failures: ['count==0'],
    },
};

// One name per kind of page, so the summary groups by page and not by UUID.
function pageName(url) {
    return (
        url
            .replace(BASE, '')
            .replace(/\?.*$/, '')
            .replace(new RegExp(UUID.source, 'g'), ':uuid') || '/'
    );
}

function recordTiming(response) {
    const header = response.headers['Server-Timing'] || '';
    const app = /app;dur=([\d.]+)/.exec(header);
    const db = /db;dur=([\d.]+);desc="(\d+) queries"/.exec(header);
    const tags = { page: pageName(response.url) };

    if (app) appTime.add(Number(app[1]), tags);
    if (db) {
        dbTime.add(Number(db[1]), tags);
        dbQueries.add(Number(db[2]), tags);
    }
}

function get(path, type) {
    const response = http.get(`${BASE}${path}`, {
        tags: { type, page: pageName(`${BASE}${path}`) },
    });
    recordTiming(response);
    check(response, {
        [`GET ${pageName(BASE + path)} 200`]: (r) => r.status === 200,
        // A page for signed-in people that ends on the sign-in page measured
        // the redirect, not the page (a 200 all the same).
        'still signed in': (r) => type === 'auth' || !r.url.includes('/login'),
    });
    return response;
}

function xsrf() {
    const cookie = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return cookie ? decodeURIComponent(cookie[0]) : '';
}

function post(path, body, type) {
    const response = http.post(`${BASE}${path}`, body, {
        headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' },
        tags: { type, page: pageName(`${BASE}${path}`) },
    });
    recordTiming(response);
    return response;
}

function signIn(user) {
    get('/login', 'auth');

    const password = post(
        '/login',
        { email: user.email, password: PASSWORD },
        'auth',
    );
    if (!password.url.includes('/two-factor-challenge')) {
        signInFailures.add(1, { step: 'password' });
        return false;
    }

    const code = post(
        '/two-factor-challenge',
        { code: totp(user.secret) },
        'auth',
    );
    const ok = code.url.endsWith('/dashboard');
    if (!ok) signInFailures.add(1, { step: 'two-factor' });

    return ok;
}

let signedIn = false;

function ensureSignedIn(user) {
    if (!signedIn) {
        signedIn = signIn(user);
        if (!signedIn) sleep(5);
    }
    return signedIn;
}

function pause() {
    sleep(2 + Math.random() * 3);
}

// Warm-up (`k6 run --vus 1 --iterations 1`): every page once, in sequence.
export default function () {
    for (const role of [requester, approver]) {
        signedIn = false;
        http.cookieJar().clear(BASE);
        role();
    }
}

export function requester() {
    if (users.length < 60 + requesterVus) {
        fail(
            `users.csv has ${users.length} users; STAFF_VUS=${requesterVus} needs ${60 + requesterVus}`,
        );
    }
    if (!ensureSignedIn(users[60 + ((__VU - 1) % (users.length - 60))])) return;

    get('/requests', 'read');
    pause();
    get('/requests/new', 'read');
    pause();

    const draft = post(
        '/requests',
        {
            title: `Load test request ${__VU}-${__ITER}`,
            details: 'Written by the load test.',
            amount: String((__ITER % 50) * 10 + 0.5),
        },
        'write',
    );
    const uuid = UUID.exec(draft.url);
    if (
        !check(draft, {
            'draft saved': () => uuid !== null && draft.status === 200,
        })
    ) {
        return;
    }
    created.add(1);
    pause();

    const sent = post(`/requests/${uuid[0]}/submit`, {}, 'write');
    if (
        check(sent, {
            'request submitted': (r) =>
                r.status === 200 && r.url.endsWith(uuid[0]),
        })
    ) {
        submitted.add(1);
    }
    pause();

    get('/dashboard', 'read');
    pause();
}

export function approver() {
    // perf-51 to perf-60: ten approvers shared by the approver VUs.
    if (!ensureSignedIn(users[50 + ((__VU - 1) % 10)])) return;

    const queue = get(
        '/requests?filter[scope]=to_decide&sort=submitted_at&direction=asc&per_page=25',
        'read',
    );
    const uuids = [
        ...new Set(queue.body.match(new RegExp(UUID.source, 'g')) || []),
    ];
    pause();
    if (uuids.length === 0) return;

    // Among the oldest, so approvers sometimes pick the same request.
    const uuid = uuids[Math.floor(Math.random() * Math.min(5, uuids.length))];
    get(`/requests/${uuid}`, 'read');
    pause();

    const reject = Math.random() < 0.3;
    const answer = post(
        `/requests/${uuid}/decision`,
        reject
            ? { decision: 'reject', note: 'Not this quarter.' }
            : { decision: 'approve' },
        'write',
    );
    check(answer, { 'decision answered': (r) => r.status === 200 });

    // The refusal comes back as the page's errors.decision (the sentence
    // itself is in every page's translation dictionary, so it proves
    // nothing).
    if (/errors\W{1,16}decision/.test(answer.body)) {
        alreadyDecided.add(1);
    } else if (answer.status === 200) {
        decided.add(1);
    }
    pause();
}
