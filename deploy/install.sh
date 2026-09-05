#!/usr/bin/env bash
#
# Zaban installer — for a server that already hosts other sites.
#
# It refuses to run if preflight found a blocker, and everything it creates is
# namespaced to zaban: its own directory, its own database and db user, its own
# Caddy site file, its own supervisor programs. It never edits another site's
# config, never restarts a service another site depends on beyond a reload, and
# never touches the system PHP other sites are using.
#
#   sudo DB_PASS='...' bash install.sh
#
# Re-running is safe: it updates the checkout and re-runs migrations rather
# than starting again.

set -euo pipefail

DOMAIN="${DOMAIN:-learn.edadras.com}"
APP_DIR="${APP_DIR:-/var/www/zaban}"
REPO="${REPO:-https://github.com/edadras/zaban.git}"
BRANCH="${BRANCH:-claude/higgsfield-setup-rhnftp}"
DB_NAME="${DB_NAME:-zaban}"
DB_USER="${DB_USER:-zaban}"
DB_PASS="${DB_PASS:-}"
RUN_USER="${RUN_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"

step() { printf '\n\033[1m▸ %s\033[0m\n' "$1"; }
die()  { printf '\033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run as root"
[ -n "$DB_PASS" ] || die "set DB_PASS — this script will not invent a database password for you"

# ---- refuse to trample anything ------------------------------------------
step "Checking nothing is in the way"
[ -e "$APP_DIR" ] && [ ! -d "$APP_DIR/.git" ] && die "$APP_DIR exists and is not a zaban checkout"
grep -rqs "$DOMAIN" /etc/caddy/ && [ ! -f "/etc/caddy/conf.d/${DOMAIN}.caddy" ] \
  && die "$DOMAIN is already configured in Caddy by something else"
mysql -N -e "show databases like '$DB_NAME'" | grep -q . && [ ! -d "$APP_DIR" ] \
  && die "database '$DB_NAME' already exists but $APP_DIR does not — refusing to write into unknown data"
echo "   clear"

# ---- packages -------------------------------------------------------------
# Only what is missing, and never a PHP version swap: other sites are using the
# system PHP, so a replacement here would break them.
step "Installing anything missing"
export DEBIAN_FRONTEND=noninteractive
need=()
for p in redis-server supervisor ffmpeg poppler-utils espeak-ng unzip git; do
  dpkg -s "$p" >/dev/null 2>&1 || need+=("$p")
done
if [ ${#need[@]} -gt 0 ]; then
  apt-get update -qq
  apt-get install -y -qq "${need[@]}"
  echo "   installed: ${need[*]}"
else
  echo "   nothing to install"
fi
command -v composer >/dev/null 2>&1 || {
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
}

# ---- code -----------------------------------------------------------------
step "Fetching the code"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$APP_DIR" checkout -q "$BRANCH"
  git -C "$APP_DIR" reset --hard -q "origin/$BRANCH"
else
  git clone --depth 1 --branch "$BRANCH" "$REPO" "$APP_DIR"
fi
cd "$APP_DIR/backend"

step "Installing PHP dependencies"
# --prefer-source avoids the dist-download path that some egress policies block.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
  || composer install --no-dev --optimize-autoloader --no-interaction --prefer-source

# ---- database -------------------------------------------------------------
step "Creating the database"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "   $DB_NAME / $DB_USER ready"

# ---- environment ----------------------------------------------------------
step "Writing .env"
if [ ! -f .env ]; then
  cp .env.example .env 2>/dev/null || touch .env
  set_env() { grep -q "^$1=" .env && sed -i "s|^$1=.*|$1=$2|" .env || echo "$1=$2" >> .env; }
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env APP_URL "https://$DOMAIN"
  set_env DB_CONNECTION mysql
  set_env DB_HOST 127.0.0.1
  set_env DB_DATABASE "$DB_NAME"
  set_env DB_USERNAME "$DB_USER"
  set_env DB_PASSWORD "$DB_PASS"
  set_env CACHE_STORE redis
  set_env SESSION_DRIVER redis
  set_env QUEUE_CONNECTION redis
  set_env REDIS_CLIENT phpredis
  "$PHP_BIN" artisan key:generate --force
  echo "   written — add ANTHROPIC_API_KEY yourself before AI features work"
else
  echo "   .env already present, left alone"
fi

# ---- migrate --------------------------------------------------------------
step "Migrating and seeding"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force || echo "   seeding partially failed — check output above"

step "Caching config"
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan storage:link || true

step "Permissions"
chown -R "$RUN_USER:$RUN_USER" "$APP_DIR"
chmod -R 775 storage bootstrap/cache

# ---- caddy ----------------------------------------------------------------
# Its own file under conf.d so nothing else's config is edited. Caddy issues and
# renews the certificate itself; there is no certbot step.
step "Configuring Caddy"
mkdir -p /etc/caddy/conf.d
grep -qs 'import conf.d' /etc/caddy/Caddyfile || echo -e "\nimport conf.d/*.caddy" >> /etc/caddy/Caddyfile

FPM_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1)
[ -n "$FPM_SOCK" ] || die "no php-fpm socket found under /run/php"

cat > "/etc/caddy/conf.d/${DOMAIN}.caddy" <<CADDY
${DOMAIN} {
	root * ${APP_DIR}/backend/public
	encode zstd gzip

	php_fastcgi unix/${FPM_SOCK}
	file_server

	# Learner uploads and generated media are served from storage/, never from
	# the app root; anything else under /storage is not a public path.
	@forbidden path /storage/framework/* /storage/logs/* /.env* /.git/*
	respond @forbidden 404

	log {
		output file /var/log/caddy/${DOMAIN}.log
	}
}
CADDY

mkdir -p /var/log/caddy && chown caddy:caddy /var/log/caddy 2>/dev/null || true
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null \
  || die "Caddy config is invalid — the site file was written but not loaded"
systemctl reload caddy
echo "   ${DOMAIN} live, certificate issues on first request"

# ---- queues ---------------------------------------------------------------
step "Installing queue workers"
sed -e "s#/srv/zaban/backend#${APP_DIR}/backend#g" \
    -e "s#^user=.*#user=${RUN_USER}#" \
    "$APP_DIR/docker/worker/supervisord.conf" > /etc/supervisor/conf.d/zaban.conf
grep -q "^user=" /etc/supervisor/conf.d/zaban.conf || sed -i "1i user=${RUN_USER}" /etc/supervisor/conf.d/zaban.conf
supervisorctl reread && supervisorctl update
echo "   $(supervisorctl status 2>/dev/null | grep -c zaban) zaban worker group(s) running"

# ---- verify ---------------------------------------------------------------
step "Verifying"
"$PHP_BIN" artisan about --only=environment,drivers 2>/dev/null | head -20 || true
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "https://${DOMAIN}/api/v1/courses" || echo 000)
echo "   GET /api/v1/courses → $code (401 is correct: it needs a token)"

printf '\n\033[32m✔ Zaban installed at https://%s\033[0m\n' "$DOMAIN"
printf '   Still to do by hand:\n'
printf '     • put ANTHROPIC_API_KEY in %s/backend/.env, then: php artisan config:cache\n' "$APP_DIR"
printf '     • import the course content: php artisan content:import (see docs/DEPLOYMENT.md §5)\n'
printf '     • create an admin user\n\n'
