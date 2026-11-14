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
# The database is SQLite unless APP_DB=pgsql: then PostgreSQL 18 on this
# runner, one owner role for the application, and with POOLER=pgbouncer
# the application connects through PgBouncer (transaction pooling) instead
# of opening a connection per request (ADR-0017; the load job in
# operations.yml measures both). PLATFORM_SERVER_TIMING=true adds the
# Server-Timing header the load scripts read.
#
#   scripts/ci/server-setup.sh
#   APP_DB=pgsql POOLER=pgbouncer scripts/ci/server-setup.sh
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
APP_DB=${APP_DB:-sqlite}
POOLER=${POOLER:-none}

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

# PostgreSQL 18 (and PgBouncer) from the PostgreSQL project's packages.
if [ "$APP_DB" = "pgsql" ]; then
    # The runner image may already carry these packages; adding them again
    # upgrades postgresql-common over libpq-dev and breaks it.
    if ! apt-cache show postgresql-18 > /dev/null 2>&1; then
        retry sudo apt-get install -y -qq postgresql-common
        retry sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y > /dev/null
    fi
    # The image's libpq-dev cannot follow libpq5 to a newer release (it and
    # postgresql-common both ship pg_config); nothing here needs it.
    sudo apt-get remove -y -qq libpq-dev > /dev/null 2>&1 || true
    sudo apt-get install -y -qq --no-install-recommends postgresql-18 "php$PHP-pgsql"
    [ "$POOLER" = "pgbouncer" ] && sudo apt-get install -y -qq --no-install-recommends pgbouncer
    sudo service postgresql start
    DB_PASSWORD=$(openssl rand -hex 24)
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
CREATE ROLE ${APP}_owner LOGIN PASSWORD '$DB_PASSWORD';
CREATE DATABASE $APP OWNER ${APP}_owner;
SQL
    DB_PORT=5432
    if [ "$POOLER" = "pgbouncer" ]; then
        # Transaction pooling; prepared statements tracked by PgBouncer (1.21+).
        sudo tee /etc/pgbouncer/pgbouncer.ini > /dev/null <<INI
[databases]
$APP = host=127.0.0.1 port=5432 dbname=$APP

[pgbouncer]
listen_addr = 127.0.0.1
listen_port = 6432
auth_type = scram-sha-256
auth_file = /etc/pgbouncer/userlist.txt
pool_mode = transaction
max_prepared_statements = 200
default_pool_size = 20
max_client_conn = 500
INI
        echo "\"${APP}_owner\" \"$DB_PASSWORD\"" | sudo tee /etc/pgbouncer/userlist.txt > /dev/null
        sudo chown postgres:postgres /etc/pgbouncer/userlist.txt && sudo chmod 600 /etc/pgbouncer/userlist.txt
        sudo service pgbouncer restart
        DB_PORT=6432
    fi
fi

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
$(if [ "$APP_DB" = "pgsql" ]; then printf 'DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=%s\nDB_DATABASE=%s\nDB_USERNAME=%s_owner\nDB_PASSWORD=%s\n' "$DB_PORT" "$APP" "$APP" "$DB_PASSWORD"; else printf 'DB_CONNECTION=sqlite\nDB_DATABASE=%s/shared/database/app.sqlite\n' "$BASE"; fi)
PLATFORM_SERVER_TIMING=${PLATFORM_SERVER_TIMING:-false}
DB_EMULATE_PREPARES=$([ "$POOLER" = "pgbouncer" ] && echo true || echo false)
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
