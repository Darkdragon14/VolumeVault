FROM node:24-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.js tailwind.config.js postcss.config.js tsconfig.json ./
COPY resources ./resources
RUN npm ci && npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
RUN composer dump-autoload --optimize && php artisan package:discover --ansi

FROM php:8.3-cli-alpine
WORKDIR /app

RUN apk add --no-cache docker-cli libzip-dev sqlite-dev tzdata \
    && docker-php-ext-install pdo pdo_sqlite zip

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

RUN mkdir -p /app/storage/database /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && touch /app/storage/database/database.sqlite \
    && chmod -R ug+rwX /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
