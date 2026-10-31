# WahaBuddy production image (spec L2): multi-stage so the runtime ships no
# Node/npm, Boost, or dev tooling.
#
# resources/js/routes + resources/js/actions are GENERATED build artifacts
# (gitignored), so a stage with PHP generates them before the frontend build.

FROM php:8.4-cli-alpine AS routes

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mbstring xml @composer

WORKDIR /generate

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction

COPY . .
RUN php artisan wayfinder:generate --with-form

FROM node:22-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.ts tsconfig.json components.json ./
COPY resources ./resources

# The vite wayfinder plugin runs `php artisan wayfinder:generate --with-form`
# during the build, so the node stage needs a PHP runtime and the app
# skeleton from the routes stage. Alpine's php84 satisfies the lockfile's
# PHP >= 8.4.1 platform requirement.
RUN apk add --no-cache php84 php84-mbstring php84-xml php84-openssl php84-phar php84-iconv php84-pdo php84-pdo_mysql php84-tokenizer php84-ctype php84-dom php84-fileinfo php84-curl php84-session php84-sodium \
    && ln -sf /usr/bin/php84 /usr/local/bin/php
COPY --from=routes /generate/artisan ./artisan
COPY --from=routes /generate/composer.json ./composer.json
COPY --from=routes /generate/vendor ./vendor
COPY --from=routes /generate/bootstrap ./bootstrap
COPY --from=routes /generate/app ./app
COPY --from=routes /generate/config ./config
COPY --from=routes /generate/routes ./routes
RUN mkdir -p storage/framework/views storage/framework/cache/data bootstrap/cache \
    && php artisan wayfinder:generate --with-form --skip-database \
    && npm run build

FROM php:8.4-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_mysql \
        mbstring \
        xml \
        bcmath \
        intl \
        zip \
        pcntl \
        exif \
        @composer

# ffprobe is optional (video codec detection) but included when available.
RUN apk add --no-cache fcgi ffmpeg || apk add --no-cache fcgi

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction

COPY . .
COPY --from=routes /generate/resources/js/routes ./resources/js/routes
COPY --from=routes /generate/resources/js/actions ./resources/js/actions
COPY --from=routes /generate/resources/js/wayfinder ./resources/js/wayfinder
COPY --from=frontend /build/public/build ./public/build

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views database \
    && chown -R www-data:www-data storage database bootstrap/cache public/build

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
