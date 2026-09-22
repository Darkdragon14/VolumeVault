FROM node:24-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.js tailwind.config.js postcss.config.js tsconfig.json ./
COPY resources ./resources
RUN npm ci && npm run build

FROM alpine:3.23 AS local-archive-reader
RUN apk add --no-cache build-base
COPY docker/local-archive-reader.c /src/local-archive-reader.c
RUN cc -static -O2 -Wall -Wextra -Werror /src/local-archive-reader.c -o /volumevault-local-archive-reader

FROM ghcr.io/nicholas-fedor/shoutrrr:0.21.0@sha256:977d519527cd4e09df865ee9c287424df6b9bf7f4eb6a601bb99a1f89fd0e9e5 AS shoutrrr

FROM serversideup/php:8.5-fpm-nginx-alpine AS runtime

USER root

RUN apk add --no-cache docker-cli tzdata \
    && install-php-extensions pdo_sqlite zip

COPY --from=local-archive-reader --chmod=755 /volumevault-local-archive-reader /usr/local/bin/volumevault-local-archive-reader
COPY --from=shoutrrr --chmod=755 /shoutrrr /usr/local/bin/shoutrrr

ENV APP_BASE_DIR=/app \
    NGINX_WEBROOT=/app/public \
    PHP_FPM_CHILD_PROCESS_USER=www-data \
    PHP_FPM_CHILD_PROCESS_GROUP=www-data \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false

WORKDIR /app

FROM runtime AS vendor

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data artisan ./artisan

RUN mkdir -p storage/database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi

FROM php:8.5-cli-alpine AS agent

RUN apk add --no-cache docker-cli tzdata su-exec libzip sqlite-libs \
    && apk add --no-cache --virtual .agent-build-deps $PHPIZE_DEPS libzip-dev sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl zip pdo_sqlite \
    && apk del .agent-build-deps

WORKDIR /app

ARG APP_VERSION=main
ENV APP_VERSION=${APP_VERSION} \
    LOG_CHANNEL=stderr \
    CACHE_STORE=array \
    SESSION_DRIVER=array

COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=vendor --chown=www-data:www-data /app/app ./app
COPY --from=vendor --chown=www-data:www-data /app/bootstrap ./bootstrap
COPY --from=vendor --chown=www-data:www-data /app/config ./config
COPY --from=vendor --chown=www-data:www-data /app/database/migrations ./database/migrations
COPY --from=vendor --chown=www-data:www-data /app/routes ./routes
COPY --from=vendor --chown=www-data:www-data /app/artisan /app/composer.json /app/composer.lock ./
COPY --from=local-archive-reader --chmod=755 /volumevault-local-archive-reader /usr/local/bin/volumevault-local-archive-reader
COPY --chmod=755 docker/agent-entrypoint.sh /usr/local/bin/agent-entrypoint

RUN mkdir -p storage/app/agent storage/app/docker-cli/logs storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["agent-entrypoint"]
CMD ["php", "artisan", "volumevault:agent"]

FROM runtime AS deploy

ARG APP_VERSION=main
ENV APP_VERSION=${APP_VERSION}

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --chmod=755 docker/s6-rc.d/volumevault-queue/run /etc/s6-overlay/s6-rc.d/volumevault-queue/run
COPY --chmod=755 docker/s6-rc.d/volumevault-queue-metadata/run /etc/s6-overlay/s6-rc.d/volumevault-queue-metadata/run
COPY --chmod=755 docker/s6-rc.d/volumevault-scheduler/run /etc/s6-overlay/s6-rc.d/volumevault-scheduler/run
COPY --chmod=755 docker/s6-rc.d/volumevault-agent-tls/run /etc/s6-overlay/s6-rc.d/volumevault-agent-tls/run

RUN mkdir -p /app/storage/database /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && touch /app/storage/database/database.sqlite \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-queue/type \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-queue-metadata/type \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-scheduler/type \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-agent-tls/type \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-agent-tls \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-queue \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-queue-metadata \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-scheduler

EXPOSE 8080 8443

ENTRYPOINT ["docker-entrypoint"]
CMD ["/init"]
