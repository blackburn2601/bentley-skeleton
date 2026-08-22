# syntax=docker/dockerfile:1.7
#
# One image, used unchanged in development and production (ADR-0004). Dev and prod differ in
# configuration and in what compose mounts over the top — not in what is built. "Works on my
# machine" therefore means considerably more here than usual.

# ---------------------------------------------------------------- 1. PHP dependencies
FROM composer:2 AS vendor

WORKDIR /app
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./

# --no-scripts because the application source is not here yet: Symfony's post-install
# scripts would try to warm a cache for code that does not exist. The cache mount makes a
# rebuild after an unrelated change nearly free.
#
# Two things guard against a flaky install:
#
#  1. COMPOSER_AUTH, passed as a build secret when available. Composer fetches dist zipballs
#     for GitHub-hosted packages from api.github.com, which is aggressively rate-limited for
#     unauthenticated callers — the failure surfaces as an opaque HTTP 504 partway through.
#     A token removes the cause rather than the symptom. Supply it in CI with
#     `--secret id=composer_auth,src=<file>` containing composer's auth.json shape.
#  2. A bounded retry, because even authenticated the endpoint returns the occasional 504,
#     and a lockfile install is deterministic — retrying cannot install anything different.
RUN --mount=type=cache,target=/tmp/composer \
    --mount=type=secret,id=composer_auth,required=false \
    export COMPOSER_CACHE_DIR=/tmp/composer \
    && export COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null || true)" \
    && for attempt in 1 2 3 4 5; do \
        composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
            && break; \
        if [ "$attempt" = 5 ]; then echo "composer install failed after 5 attempts" >&2; exit 1; fi; \
        echo "composer install failed (attempt $attempt) — retrying in $((attempt * 5))s" >&2; \
        sleep $((attempt * 5)); \
    done

# ---------------------------------------------------------------- 2. SPA build
FROM node:26-alpine AS spa

WORKDIR /spa
COPY frontend/package.json frontend/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------- 3. Runtime
FROM dunglas/frankenphp:php8.5 AS runtime

# OpenTelemetry is a build-time opt-in: opentelemetry-auto-symfony hard-requires the
# extension, so it has to exist when composer installs, regardless of the runtime env flag
# that decides whether it actually traces (ADR-0017).
ARG WITH_OTEL=0

RUN install-php-extensions \
        pdo_pgsql \
        redis \
        intl \
        opcache \
        zip \
    && if [ "$WITH_OTEL" = "1" ]; then install-php-extensions opentelemetry; fi

WORKDIR /app

# Non-root. FrankenPHP binds :80 and :443 inside the container, so it needs the capability
# to bind privileged ports without being root.
RUN groupadd -g 1000 app \
    && useradd -u 1000 -g app -m -s /bin/sh app \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /app/var /data /config \
    && chown -R app:app /app /data /config

COPY --chown=app:app backend/ /app/
COPY --from=vendor  --chown=app:app /app/vendor /app/vendor
COPY --from=spa     --chown=app:app /spa/dist   /app/public/
COPY --chown=app:app docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chown=app:app docker/frankenphp/php.ini   /usr/local/etc/php/conf.d/app.ini

# Dependencies that only exist for tests and code generation must not ship.
RUN rm -rf /app/tests /app/fixtures /app/var/cache/* /app/var/log/*

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # Written at build time; the read-only rootfs in compose.prod.yaml means nothing may
    # write to the image at runtime, so the cache has to already be warm.
    SERVER_NAME=:80

# The authoritative classmap and the warm cache are built here, as root, and composer is
# removed again afterwards: a build tool in a production image is extra attack surface for
# no runtime benefit. Both steps must happen at build time because the production container
# runs with a read-only root filesystem — nothing can be generated on first request.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:warmup --env=prod --no-debug \
    && rm -f /usr/local/bin/composer \
    && chown -R app:app /app/vendor /app/var

USER app

# Readiness, not liveness: the container is only "healthy" once its dependencies answer.
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:80/health/ready") === false ? 1 : 0);'

EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------- 4. Development
# Extends the production runtime rather than diverging from it: same base, same extensions,
# same web server, same user. What dev adds is the require-dev packages (makers, fixtures,
# Foundry, the debug toolbar), Xdebug, and an opcache configuration that notices edits.
#
# A dev container cannot simply run the production image with APP_ENV=dev: config/bundles.php
# enables dev-only bundles, and those are not installed by `composer install --no-dev`, so
# the kernel fails to boot on the first request.
FROM runtime AS dev

USER root

COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY docker/frankenphp/php.dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

RUN install-php-extensions xdebug

# Restore what the production stage stripped, then install with dev dependencies.
COPY --chown=app:app backend/composer.json backend/composer.lock backend/symfony.lock /app/
RUN --mount=type=cache,target=/tmp/composer \
    --mount=type=secret,id=composer_auth,required=false \
    export COMPOSER_CACHE_DIR=/tmp/composer \
    && export COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null || true)" \
    && export COMPOSER_ALLOW_SUPERUSER=1 \
    && for attempt in 1 2 3 4 5; do \
        composer install --no-scripts --no-interaction --prefer-dist && break; \
        if [ "$attempt" = 5 ]; then exit 1; fi; \
        sleep $((attempt * 5)); \
    done \
    && rm -rf /app/var/cache/* \
    && chown -R app:app /app/vendor /app/var

ENV APP_ENV=dev \
    APP_DEBUG=1 \
    XDEBUG_MODE=off

USER app

# Nothing is warmed here: the source is bind-mounted over this image, so a cache built now
# would describe code that is about to be replaced.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
