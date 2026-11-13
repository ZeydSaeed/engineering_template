#!/usr/bin/env bash
#
# Prepares a CI runner as a server for the application (ADR-0030,
# ADR-0032): Nginx and PHP-FPM from the deploy/ templates (only APP and
# HOST filled in), the application's own Linux user, /srv/APP with shared
# storage and a production .env, HTTPS. It deploys nothing: releases arrive
# with scripts/deploy/deploy.sh, as on a real server.
#
# For disposable CI runners only: it installs packages, creates a user and
# writes to /etc and /srv. It refuses to run anywhere else.
#
#   scripts/ci/server-setup.sh
#   sudo scripts/deploy/deploy.sh dist/engineering-template-<sha>.tar.gz
set -euo pipefail

# A failure names its line and command, with the server logs, as
# annotations on the run.
failed() {
    echo "::error title=server-setup.sh line $1::$2"
    for log in /var/log/nginx/error.log "/var/log/php${PHP:-8.5}-fpm.log" /srv/template/shared/storage/logs/laravel.log; do
        [ -f "$log" ] && sudo tail -n 15 "$log" | while IFS= read -r line; do echo "::error title=$(basename "$log")::$line"; done
    done
    return 0
}
trap 'failed "$LINENO" "$BASH_COMMAND"' ERR

if [ "${CI:-}" != "true" ] || [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "server-setup.sh changes the machine; it runs on CI runners only." >&2
    exit 1
fi

APP=template
# A dotted name: Java clients (ZAP) send no server name (SNI) for a bare
# `localhost`, and the template rightly refuses TLS for unnamed hosts.
HOST=${APP_HOST:-template.test}
PHP=${PHP_VERSION:-8.5}
BASE=/srv/$APP

# PHP-FPM and its CLI from the same packages (so both have the same
# extensions), with the extensions the application uses; Nginx.
# Package sources are outside services: a few attempts each.
retry() {
    for attempt in 1 2 3; do
        "$@" && return 0
        echo "::warning title=server-setup.sh::attempt $attempt failed: $*"
        sleep $((attempt * 10))
    done
    return 1
}
echo 'Acquire::Retries "3";' | sudo tee /etc/apt/apt.conf.d/80-retries > /dev/null
retry sudo apt-get update -qq
if ! apt-cache show "php$PHP-fpm" > /dev/null 2>&1; then
    retry sudo add-apt-repository -y ppa:ondrej/php > /dev/null
    retry sudo apt-get update -qq
fi
apt-cache policy "php$PHP-fpm" | head -3
sudo apt-get install -y -qq --no-install-recommends nginx "php$PHP-fpm" \
    "php$PHP-cli" "php$PHP-sqlite3" "php$PHP-mbstring" "php$PHP-xml" \
    "php$PHP-intl" "php$PHP-curl" "php$PHP-bcmath" "php$PHP-zip"
PHP_CLI=/usr/bin/php$PHP
"$PHP_CLI" -m | grep -qi pdo_sqlite

grep -q " $HOST\$" /etc/hosts || echo "127.0.0.1 $HOST" | sudo tee -a /etc/hosts > /dev/null

# The application's own user, owning its release and shared directories.
id "$APP" > /dev/null 2>&1 || sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$APP"
sudo mkdir -p "$BASE/releases" "$BASE/shared/database" \
    "$BASE/shared/storage/app/private" "$BASE/shared/storage/logs" \
    "$BASE/shared/storage/framework/cache/data" \
    "$BASE/shared/storage/framework/sessions" \
    "$BASE/shared/storage/framework/views"

# Production settings: everything the environment check asks for. Keys are
# generated here and live only on this runner.
APP_KEY="base64:$(openssl rand -base64 32)"
AUDIT_KEY=$(openssl rand -hex 32)
sudo tee "$BASE/shared/.env" > /dev/null <<ENV
APP_NAME="Engineering Template"
APP_ENV=production
APP_DEBUG=false
APP_KEY=$APP_KEY
APP_URL=https://$HOST
LOG_CHANNEL=single
LOG_LEVEL=warning
DB_CONNECTION=sqlite
DB_DATABASE=$BASE/shared/database/app.sqlite
SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=
SESSION_PATH=/
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
PLATFORM_HSTS=true
PLATFORM_CSP=true
AUDIT_HMAC_KEY=$AUDIT_KEY
BACKUP_ENCRYPT=true
ENV
sudo touch "$BASE/shared/database/app.sqlite"
sudo chown -R "$APP:$APP" "$BASE"
sudo chmod 600 "$BASE/shared/.env"


# The templates, with APP and HOST filled in.
sudo mkdir -p "/etc/ssl/$APP"
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$HOST" \
    -addext "subjectAltName=DNS:$HOST" \
    -keyout "/etc/ssl/$APP/origin.key" -out "/etc/ssl/$APP/origin.pem" 2> /dev/null
sed "s/APP/$APP/g; s/HOST/$HOST/g" deploy/nginx/app.conf | sudo tee "/etc/nginx/sites-available/$APP.conf" > /dev/null
sudo ln -sfn "/etc/nginx/sites-available/$APP.conf" "/etc/nginx/sites-enabled/$APP.conf"
sudo rm -f /etc/nginx/sites-enabled/default
sed "s/APP/$APP/g" deploy/php/app-pool.conf | sudo tee "/etc/php/$PHP/fpm/pool.d/$APP.conf" > /dev/null
sudo cp deploy/php/app-php.ini "/etc/php/$PHP/fpm/conf.d/99-$APP.ini"

check() {
    local out
    if ! out=$(sudo "$@" 2>&1); then
        while IFS= read -r line; do echo "::error title=$1 -t::$line"; done <<< "$out"
        exit 1
    fi
}
nginx -v 2>&1
check nginx -t
check "php-fpm$PHP" -t
sudo service "php$PHP-fpm" restart
sudo service nginx restart

echo "Ready for releases: https://$HOST from $BASE/current"
