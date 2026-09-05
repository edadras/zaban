#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Shared entrypoint for the app, worker, scheduler and reverb containers.
#
# It does the minimum needed for the container to be usable and then execs the
# real command. Anything that mutates the database (migrations, imports) is a
# deliberate developer action driven from the Makefile, never something that
# happens implicitly on container start — an auto-migrating container is how you
# lose a database during an unrelated restart.
# ---------------------------------------------------------------------------
set -euo pipefail

APP_DIR=/srv/zaban/backend

cd "$APP_DIR"

# 1. A usable .env. The image never bakes one; secrets come from the environment.
if [ ! -f .env ] && [ -f .env.example ]; then
    echo "entrypoint: no .env found, seeding from .env.example"
    cp .env.example .env
fi

# 2. Dependencies. Bind-mounted source means vendor/ may be empty on a fresh
#    clone; the production image already has it and skips this.
#
#    compose starts the worker and scheduler only once `app` is healthy, so in
#    the normal flow app has already installed. Starting a single container on
#    its own (`docker compose up worker`) can still race, so the install is
#    serialised through a lock on the shared mount when one can be created.
if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    LOCK=/srv/zaban/.composer-install.lock
    if command -v flock >/dev/null 2>&1 && : >"$LOCK" 2>/dev/null; then
        (
            flock 9
            if [ ! -f vendor/autoload.php ]; then
                echo "entrypoint: installing composer dependencies"
                composer install --no-interaction --prefer-dist
            else
                echo "entrypoint: dependencies installed by another container"
            fi
        ) 9>"$LOCK"
    else
        echo "entrypoint: installing composer dependencies (unlocked)"
        composer install --no-interaction --prefer-dist
    fi
fi

# 3. An application key. Without it every encrypted cookie and Sanctum session
#    fails with an opaque error.
if [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
    echo "entrypoint: generating APP_KEY"
    php artisan key:generate --force --no-interaction || true
fi

# 4. Writable runtime directories. These live in the bind mount, so their owner
#    is whatever the host says; fix it quietly rather than failing later.
mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# 5. Wait for the database. depends_on/service_healthy already covers the common
#    case; this covers a mysql restart while the app container stays up.
if [ "${WAIT_FOR_DB:-1}" = "1" ] && [ -n "${DB_HOST:-}" ]; then
    for _ in $(seq 1 60); do
        if php -r 'exit(@fsockopen(getenv("DB_HOST"), (int)(getenv("DB_PORT") ?: 3306), $e, $s, 1) ? 0 : 1);'; then
            break
        fi
        echo "entrypoint: waiting for ${DB_HOST}:${DB_PORT:-3306}…"
        sleep 2
    done
fi

exec "$@"
