# syntax=docker/dockerfile:1

# =============================================================================
# Stage 1: PHP dependencies
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# =============================================================================
# Stage 2: Runtime image (php-fpm) — the application container
# =============================================================================
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        opcache \
    && apk del .build-deps \
    && apk add --no-cache icu-libs oniguruma

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=vendor /app .

# www-data already exists in this base image, and its php-fpm pool config
# (www.conf) already runs workers as www-data — that's why the container
# itself stays root at startup: php-fpm's master process needs to start as
# root specifically so it CAN drop privileges to www-data for the workers
# that actually handle requests. Only chown the two directories Laravel
# writes to; everything else stays read-only for the app.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# =============================================================================
# Stage 3: Nginx image — serves public/ and proxies PHP requests to `app`
# =============================================================================
FROM nginx:1.27-alpine AS nginx

COPY --from=vendor /app/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
