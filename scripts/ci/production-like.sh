#!/usr/bin/env bash
#
# Serves the checked-out, built application the way a server does
# (ADR-0030): the deploy/ templates for Nginx and PHP-FPM unchanged except
# for APP and HOST, the application's own Linux user, HTTPS, a release
# directory under /srv with shared storage, and APP_ENV=production with the
# environment checks on. The ZAP scan runs against it.
#
# For disposable CI runners only: it installs packages, creates a user and
# writes to /etc and /srv. It refuses to run anywhere else.
#
#   composer install --no-dev && npm ci && npm run build
#   scripts/ci/production-like.sh            # then https://localhost
set -euo pipefail

# A failure names its line and command, with the server logs, as
# annotations on the run.
failed() {
    echo "::error title=production-like.sh line $1::$2"
    for log in /var/log/nginx/error.log "/var/log/php${PHP:-8.5}-fpm.log" /srv/template/shared/storage/logs/laravel.log; do
        [ -f "$log" ] && sudo tail -n 15 "$log" | while IFS= read -r line; do echo "::error title=$(basename "$log")::$line"; done
    done
    return 0
}
trap 'failed "$LINENO" "$BASH_COMMAND"' ERR

if [ "${CI:-}" != "true" ] || [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "production-like.sh changes the machine; it runs on CI runners only." >&2
    exit 1
fi

APP=template
HOST=localhost
PHP=${PHP_VERSION:-8.5}
SOURCE=$(pwd)
BASE=/srv/$APP
RELEASE=$BASE/releases/${GITHUB_SHA:-local}

# PHP-FPM and its CLI from the same packages (so both have the same
# extensions), with the extensions the application uses; Nginx.
sudo apt-get update -qq
if ! apt-cache show "php$PHP-fpm" > /dev/null 2>&1; then
    sudo add-apt-repository -y ppa:ondrej/php > /dev/null
    sudo apt-get update -qq
fi
apt-cache policy "php$PHP-fpm" | head -3
sudo apt-get install -y -qq --no-install-recommends nginx "php$PHP-fpm" \
    "php$PHP-cli" "php$PHP-sqlite3" "php$PHP-mbstring" "php$PHP-xml" \
    "php$PHP-intl" "php$PHP-curl" "php$PHP-bcmath" "php$PHP-zip"
PHP_CLI=/usr/bin/php$PHP
"$PHP_CLI" -m | grep -qi pdo_sqlite

# The application's own user, owning its release and shared directories.
id "$APP" > /dev/null 2>&1 || sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$APP"
sudo mkdir -p "$RELEASE" "$BASE/shared/database" \
    "$BASE/shared/storage/app/private" "$BASE/shared/storage/logs" \
    "$BASE/shared/storage/framework/cache/data" \
    "$BASE/shared/storage/framework/sessions" \
    "$BASE/shared/storage/framework/views"

sudo rsync -a --exclude .git --exclude node_modules --exclude storage \
    --exclude .env --exclude 'database/*.sqlite' "$SOURCE/" "$RELEASE/"
sudo ln -sfn "$BASE/shared/storage" "$RELEASE/storage"

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
sudo ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
sudo touch "$BASE/shared/database/app.sqlite"
sudo chown -R "$APP:$APP" "$BASE"
sudo chmod 600 "$BASE/shared/.env"

as_app() { sudo -u "$APP" -- "$@"; }
(
    cd "$RELEASE"
    as_app "$PHP_CLI" artisan migrate --force --no-interaction
    as_app "$PHP_CLI" artisan config:cache
    as_app "$PHP_CLI" artisan route:cache
    as_app "$PHP_CLI" artisan view:cache
)
sudo ln -sfn "$RELEASE" "$BASE/current"

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

sudo nginx -t
sudo "php-fpm$PHP" -t
sudo service "php$PHP-fpm" restart
sudo service nginx restart

# Served, through Nginx and FPM, in production mode.
for _ in $(seq 1 20); do
    curl -sfk "https://$HOST/up" > /dev/null && break
    sleep 1
done
curl -sfk "https://$HOST/up" > /dev/null
test "$(curl -sk -o /dev/null -w '%{http_code}' "https://$HOST/login")" = 200
echo "Serving https://$HOST from $RELEASE"
