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

# HTTPS from the container itself, with a certificate the operator installed: a second site on
# 8443 that hands each request to the plain one on APP_PORT, as a reverse proxy in front would.
# So the app has to trust this container's own address, or it would take every request for http.
if [ -n "${NUGGET_TLS_CERT:-}" ] || [ -n "${NUGGET_TLS_KEY:-}" ]; then
    for file in "${NUGGET_TLS_CERT:-}" "${NUGGET_TLS_KEY:-}"; do
        if [ -z "$file" ] || ! as_app test -r "$file"; then
            echo "nugget: NUGGET_TLS_CERT and NUGGET_TLS_KEY must each name a file the app user (uid 1000) can read. '$file' is not one." >&2
            exit 1
        fi
    done
    NUGGET_TLS_SITE="https://:8443 {
	tls $NUGGET_TLS_CERT $NUGGET_TLS_KEY
	reverse_proxy 127.0.0.1:${APP_PORT}
}"
    TRUSTED_PROXIES="${TRUSTED_PROXIES:+$TRUSTED_PROXIES,}127.0.0.1"
    export NUGGET_TLS_SITE TRUSTED_PROXIES
fi

if [ "${NUGGET_MIGRATE:-1}" = 1 ]; then
    as_app php artisan migrate --force --no-interaction
fi

# Config, routes, views and events, cached in this container's own filesystem.
as_app php artisan optimize --no-interaction >/dev/null

exec su-exec app "$@"
