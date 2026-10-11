#!/bin/sh
# Container entrypoint for the shipped image: prepare /app/data, keep APP_KEY, migrate, cache,
# then exec the command as the unprivileged `app` user.
#
# The same image runs the site and its scheduler (docker-compose-live.yml). Only the site
# migrates: the scheduler is started with NUGGET_MIGRATE=0 and waits for the site to be healthy.
set -eu
cd /app

as_app() { su-exec app "$@"; }

DATA=/app/data
mkdir -p "$DATA/private" "$DATA/logs"
# A bind mount arrives owned by the host user; fix once (skip the walk when already right).
if [ "$(stat -c %U "$DATA")" != app ] || [ "$(stat -c %U "$DATA/private")" != app ]; then
    chown -R app:app "$DATA"
fi
chown app:app "$DATA/private" "$DATA/logs"
chmod 700 "$DATA/private"

if [ "${DB_CONNECTION:-sqlite}" = sqlite ] && [ ! -f "$DB_DATABASE" ]; then
    as_app touch "$DB_DATABASE"
fi

# APP_KEY encrypts sessions and every exchange's API key and secret: env wins, else the kept key,
# else make one. The scheduler reads the key the site made.
KEY_FILE="$DATA/private/.app-key"
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        as_app sh -c "umask 077 && php -r 'echo \"base64:\".base64_encode(random_bytes(32));' > '$KEY_FILE'"
        echo "nugget: generated APP_KEY in $KEY_FILE. Back it up with the database: the exchange keys cannot be read without it."
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "${NUGGET_MIGRATE:-1}" = 1 ]; then
    as_app php artisan migrate --force --no-interaction
fi

# Config, routes, views and events, cached in this container's own filesystem.
as_app php artisan optimize --no-interaction >/dev/null

exec su-exec app "$@"
