FROM composer:2 AS vendor
RUN apk add --no-cache --virtual .pcntl-build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pcntl \
    && apk del .pcntl-build-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM vendor AS wayfinder
COPY . .
RUN php artisan wayfinder:generate --with-form

FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder
COPY public ./public
COPY vite.config.ts tsconfig.json eslint.config.js .prettierrc* ./
ARG VITE_APP_NAME=Peekchimp
ENV VITE_APP_NAME=$VITE_APP_NAME \
    WAYFINDER_COMMAND=true
RUN npm run build

FROM php:8.4-cli-alpine
WORKDIR /var/www/html
RUN apk add --no-cache libpq libzip icu-libs oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pcntl pdo_pgsql mbstring intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY . .
RUN mkdir -p storage/app/private/passport storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
