# Зависимости ставятся на этапе сборки: в закрытом контуре (ТЗ 6.3) composer наружу не ходит
FROM composer:2.10.3@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-req=ext-intl

COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM php:8.5-fpm-alpine@sha256:ce1dcc234879feab0f309100e55e89e7cf21b9085e76de2a03a8240cec02751e

# pdo_pgsql — ADR-0014, redis — ADR-0019, pcntl — сигналы queue:work в kb-worker; opcache в образе уже есть
RUN apk add --no-cache libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl intl zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
