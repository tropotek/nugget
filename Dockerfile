# syntax=docker/dockerfile:1
#
# Targets:
#   dev   — local development image (docker-compose.yml): bookworm + Composer + Node, code bind-mounted.
#
# The shipped image (prod target) is added by task 90.

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
