FROM node:24-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.js tailwind.config.js postcss.config.js tsconfig.json ./
COPY resources ./resources
RUN npm ci && npm run build

FROM serversideup/php:8.5-fpm-nginx-alpine AS runtime

USER root

RUN apk add --no-cache docker-cli tzdata \
    && install-php-extensions pdo_sqlite zip

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

FROM runtime AS deploy

ARG APP_VERSION=main
ENV APP_VERSION=${APP_VERSION}

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --chmod=755 docker/s6-rc.d/volumevault-queue/run /etc/s6-overlay/s6-rc.d/volumevault-queue/run
COPY --chmod=755 docker/s6-rc.d/volumevault-scheduler/run /etc/s6-overlay/s6-rc.d/volumevault-scheduler/run

RUN mkdir -p /app/storage/database /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && touch /app/storage/database/database.sqlite \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-queue/type \
    && printf 'longrun\n' > /etc/s6-overlay/s6-rc.d/volumevault-scheduler/type \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-queue \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/volumevault-scheduler

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint"]
CMD ["/init"]
