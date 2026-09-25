# Зависимости ставятся на этапе сборки: в закрытом контуре (ТЗ 6.3) composer наружу не ходит
FROM composer:2.10.3@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-req=ext-intl

COPY . .
# --no-scripts: artisan здесь запускается на PHP без intl/pdo_pgsql, а его результат всё равно отбрасывается —
# package:discover и filament:assets выполняются в финальном образе
RUN composer dump-autoload --no-dev --optimize --no-scripts

# Vite-сборка: публичные ассеты (public/build/) попадают в финальный образ
FROM node:24-alpine@sha256:83f1c388c31fb2e51f7cbd4dea949b96260798c98f206e8e4696bc93bd964e3a AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY . .
# Весь vendor, а не только filament: resources/css/app.css импортирует CSS пакетов filament
# и сканирует blade-шаблоны пагинации Laravel; недостающий @source Tailwind пропускает молча
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM php:8.5-fpm-alpine@sha256:ce1dcc234879feab0f309100e55e89e7cf21b9085e76de2a03a8240cec02751e

# pdo_pgsql — ADR-0014, redis — ADR-0019, pcntl — сигналы queue:work в kb-worker; bcmath — dev-пакет laravel-lang
# (lang:update в контейнере из этого образа); opcache в образе уже есть; poppler-utils — гейт текстового слоя PDF (ADR-0012)
RUN apk add --no-cache libpq icu-libs libzip poppler-utils \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl intl zip bcmath \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

# /data/documents — точка монтирования kb-documents (ADR-0018): пустой том при первом подключении
# наследует владельца каталога из образа, иначе php-fpm под www-data не сможет в него писать
RUN php artisan package:discover --ansi \
    && php artisan filament:assets \
    && mkdir -p /data/documents \
    && chown -R www-data:www-data storage bootstrap/cache /data/documents

EXPOSE 9000
