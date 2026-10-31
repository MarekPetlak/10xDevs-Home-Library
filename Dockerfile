FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

FROM php:8.4-fpm-alpine AS app
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor \
    && install-php-extensions pdo_mysql intl opcache zip
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/supervisord.conf /etc/supervisord.conf

ENV APP_ENV=prod
ARG BUILD_APP_SECRET=build-only-secret
ARG BUILD_DATABASE_URL="mysql://u:p@127.0.0.1:3306/db?serverVersion=8.0&charset=utf8mb4"
RUN APP_SECRET=$BUILD_APP_SECRET DATABASE_URL=$BUILD_DATABASE_URL TRUSTED_PROXIES=REMOTE_ADDR \
        sh -c 'php bin/console importmap:install --env=prod && php bin/console asset-map:compile --env=prod && php bin/console cache:warmup --env=prod' \
    && mkdir -p var/cache var/log && chown -R www-data:www-data var

EXPOSE 8080
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
