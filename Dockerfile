# syntax=docker/dockerfile:1
#
# Targets:
#   dev   — local development image (docker-compose.yml): bookworm + Composer + Node, code bind-mounted.
#   prod  — the shipped image (default): Alpine, no build tools, everything persistent under /app/data.

ARG FRANKENPHP=1.13-php8.4

############################ dev ############################
FROM dunglas/frankenphp:${FRANKENPHP}-bookworm AS dev

# bcmath: all money and unit maths. pdo_mysql: the tkbtc importer reads a MariaDB database.
RUN install-php-extensions pdo_sqlite pdo_mysql intl zip pcntl gd bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app \
 && useradd -u ${UID} -g app -m app \
 && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
 && mkdir -p /config/psysh \
 && chown -R app:app /config/caddy /config/psysh /data/caddy

USER app
WORKDIR /app

############################ prod: shared runtime base ############################
FROM dunglas/frankenphp:${FRANKENPHP}-alpine AS base

# bcmath: all money and unit maths. gd: dompdf. pdo_mysql: the tkbtc importer reads a MariaDB database.
RUN install-php-extensions pdo_sqlite pdo_mysql intl zip pcntl gd bcmath opcache

WORKDIR /app

############################ prod: PHP dependencies ############################
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
 && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

############################ prod: front-end build ############################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
RUN npm ci --no-audit --no-fund && npm run build

############################ prod: runtime ############################
FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-nugget.ini"
COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

RUN apk add --no-cache su-exec \
 && addgroup -g 1000 app && adduser -D -u 1000 -G app app

COPY --from=vendor --chown=app:app /app /app
# Release builds stamp their version (shown on /up); other builds keep version.md as committed.
ARG APP_VERSION=
RUN if [ -n "$APP_VERSION" ]; then echo "${APP_VERSION#v}" > /app/version.md; fi
COPY --from=assets --chown=app:app /app/public/build /app/public/build

# Persistent state lives in /app/data (one volume): the SQLite database, private/ and logs/.
RUN mkdir -p data/private data/logs storage/app/dompdf storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && ln -s /app/docker/artisan /usr/local/bin/artisan \
 && chown -R app:app /app/data /app/storage /app/bootstrap/cache /app/public /config/caddy /data/caddy

# The cache and the sessions are in the database, which the app and the scheduler containers share:
# a lock one takes, such as the one on an exchange's sync, is seen by the other.
ENV APP_NAME=Nugget \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr,data \
    LOG_LEVEL=info \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/data/nugget.sqlite \
    OCTANE_SERVER=frankenphp \
    APP_PORT=8080

# Starts as root only to fix /app/data ownership; entry.sh drops to `app` for everything else.
# 8443 answers only when a certificate is given (NUGGET_TLS_CERT, NUGGET_TLS_KEY).
EXPOSE 8080 8443
VOLUME ["/app/data"]
ENTRYPOINT ["/app/docker/entry.sh"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${APP_PORT}/up" || exit 1

CMD ["sh", "-c", "exec php artisan octane:frankenphp --caddyfile=docker/Caddyfile --host=0.0.0.0 --port=${APP_PORT} --workers=auto --max-requests=500"]
