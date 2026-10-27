# syntax=docker/dockerfile:1
# One image for every environment: `dev` (Tilt live-sync, dev deps) and `prod` (optimized, no dev deps).
FROM dunglas/frankenphp:1.13-php8.5 AS base

RUN install-php-extensions pdo_pgsql rdkafka redis intl bcmath pcntl zip opcache opentelemetry protobuf \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM base AS dev
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload \
    && php artisan package:discover --ansi \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R a+w storage bootstrap/cache

FROM base AS prod
ENV APP_ENV=production APP_DEBUG=false
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R a+w storage bootstrap/cache
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
