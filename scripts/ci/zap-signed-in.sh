#!/usr/bin/env bash
# An active ZAP scan, signed in as an administrator (Phase 10 follow-ups;
# the baseline in security.yml is passive and signed out). Against the
# release deployed on a disposable CI runner by server-setup.sh and
# deploy.sh, never against a real server.
#
# Signs in for real (password, then the code of the account's own
# authenticator secret), gives ZAP the session cookie on every request,
# spiders and actively attacks what it finds. Fails on any High alert;
# every other alert becomes an annotation. The server's access log shows
# which signed-in pages were reached.
#
#   scripts/ci/zap-signed-in.sh
set -euo pipefail

if [ "${CI:-}" != "true" ] || [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "zap-signed-in.sh attacks the server it is pointed at; CI runners only." >&2
    exit 1
fi

ZAP_IMAGE="ghcr.io/zaproxy/zaproxy:2.17.0@sha256:9624669db72f54d86c4b46a7064518bd9bf79d8c61a4c31befa9923489f328af"
APP=template
HOST=template.test
CURRENT=/srv/$APP/current
PHP_CLI=/usr/bin/php${PHP_VERSION:-8.5}
OUT="$PWD/zap-signed-in"
mkdir -p "$OUT" && chmod 777 "$OUT"

# One administrator with an authenticator secret (PerformanceSeeder's first
# users are administrators); the seeder refuses anything but local, hence
# APP_ENV=local for this one command.
DATABASE=$(sudo grep '^DB_DATABASE=' "/srv/$APP/shared/.env" | cut -d= -f2-)
(cd "$CURRENT" && sudo -u "$APP" "$PHP_CLI" artisan config:clear > /dev/null \
    && sudo -u "$APP" env APP_ENV=local PERF_DATABASE="$DATABASE" PERF_USERS=12 PERF_AUDIT_EVENTS=0 \
        "$PHP_CLI" artisan db:seed --class=PerformanceSeeder --force --no-interaction > /dev/null \
    && sudo -u "$APP" "$PHP_CLI" artisan config:cache > /dev/null)
CSV=$(sudo cat "/srv/$APP/shared/storage/app/private/perf/users.csv")

# Sign in; print the Cookie header of the signed-in session.
COOKIE=$(python3 - "$HOST" "$CSV" <<'PY'
import base64, hashlib, hmac, http.cookiejar, ssl, struct, sys, time, urllib.parse, urllib.request

host, csv = sys.argv[1], sys.argv[2]
email, secret = csv.strip().split('\n')[1].split(',')

def totp(secret):
    key = base64.b32decode(secret.strip().upper())
    digest = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    return f"{(struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1_000_000:06d}"

context = ssl.create_default_context()
context.check_hostname = False
context.verify_mode = ssl.CERT_NONE
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(jar))
base = f'https://{host}'

def xsrf():
    return next(urllib.parse.unquote(c.value) for c in jar if c.name == 'XSRF-TOKEN')

def post(path, fields):
    request = urllib.request.Request(base + path, data=urllib.parse.urlencode(fields).encode(),
                                     headers={'X-XSRF-TOKEN': xsrf(), 'Accept': 'text/html'})
    return opener.open(request)

opener.open(base + '/login')
if '/two-factor-challenge' not in post('/login', {'email': email, 'password': 'Perf-Passw0rd!long'}).url:
    sys.exit('sign-in: the password step failed')
if time.time() % 30 > 25:
    time.sleep(31 - time.time() % 30)
if not post('/two-factor-challenge', {'code': totp(secret)}).url.endswith('/dashboard'):
    sys.exit('sign-in: the authenticator step failed')
if not opener.open(base + '/admin/users').url.endswith('/admin/users'):
    sys.exit('sign-in: the session does not reach an administrator page')
# No spaces: ZAP splits its -z options on whitespace.
print(';'.join(f'{c.name}={c.value}' for c in jar))
PY
)
echo "Signed in; the session reaches /admin/users."

# Every request ZAP sends carries the session (replacer rule). The pages are
# an Inertia application, whose links live in page data rendered by
# JavaScript, so the AJAX spider (a real browser, -j) finds them; the
# sign-out address is excluded everywhere, so no click ends the session;
# the classic spider does not submit forms; the active scan is
# capped at 20 minutes and paced under the server's per-client limit (Nginx:
# 20 requests a second, burst 40), so the scan tests the application, not
# the limiter.
sudo truncate -s 0 /var/log/nginx/access.log
status=0
docker run --rm --network host --add-host "$HOST:127.0.0.1" -v "$OUT:/zap/wrk:rw" "$ZAP_IMAGE" \
    zap-full-scan.py -t "https://$HOST/dashboard" -m 3 -j -J report.json -r report.html -I \
    -z "-config replacer.full_list(0).description=session \
        -config replacer.full_list(0).enabled=true \
        -config replacer.full_list(0).matchtype=REQ_HEADER \
        -config replacer.full_list(0).matchstr=Cookie \
        -config replacer.full_list(0).regex=false \
        -config replacer.full_list(0).replacement=$COOKIE \
        -config spider.processform=false -config spider.postform=false \
        -config scanner.maxScanDurationInMins=20         -config scanner.threadPerHost=2 -config scanner.delayInMs=100         -config spider.thread=2         -config ajaxSpider.maxDuration=8 -config ajaxSpider.numberOfBrowsers=1         -config globalexcludeurl.url_list.url(0).regex=.*/logout.*         -config globalexcludeurl.url_list.url(0).enabled=true         -config globalexcludeurl.url_list.url(0).description=sign-out" \
    > "$OUT/zap.log" 2>&1 || status=$?
if [ "$status" -eq 3 ] || [ ! -f "$OUT/report.json" ]; then
    grep -v -E 'Pull|Download|Verifying|Waiting|Digest|Status' "$OUT/zap.log" | tail -n 12 | while IFS= read -r line; do echo "::error title=ZAP exit $status::$line"; done
    exit 1
fi

# Which signed-in pages ZAP reached (200 from pages behind sign-in).
signed_in=$(sudo awk '$9 == 200 {print $7}' /var/log/nginx/access.log | sed 's/?.*//' \
    | grep -E '^/(dashboard|admin|requests|settings|notifications)' | sort -u)
count=$(printf '%s\n' "$signed_in" | grep -c . || true)
echo "::notice title=ZAP signed in::reached $count signed-in paths: $(printf '%s ' $signed_in | cut -c1-600)"
[ "$count" -ge 5 ] || { echo "::error::ZAP reached only $count signed-in paths: the session did not hold"; exit 1; }

python3 - "$OUT/report.json" <<'PY'
import json, sys
report = json.load(open(sys.argv[1]))
high = 0
for site in report.get('site', []):
    for alert in site.get('alerts', []):
        risk = int(alert.get('riskcode', 0))
        uris = sorted({i.get('uri', '') for i in alert.get('instances', [])})[:3]
        level = 'error' if risk >= 3 else 'warning'
        high += risk >= 3
        print(f"::{level} title=ZAP {alert.get('pluginid')}::{alert.get('name')} ({alert.get('riskdesc')}) {' '.join(uris)}")
print(f"::notice title=ZAP signed in::{high} High alerts")
sys.exit(1 if high else 0)
PY
