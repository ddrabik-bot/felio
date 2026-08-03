FROM node:22-alpine AS frontend

WORKDIR /var/www/html
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .
COPY --from=frontend /var/www/html/public/build ./public/build
RUN composer dump-autoload --optimize
COPY .docker/entrypoint.sh /usr/local/bin/felio-entrypoint
RUN sed -i 's/^listen = 9000$/listen = 0.0.0.0:9000/' /usr/local/etc/php-fpm.d/docker.conf \
    && chmod +x /usr/local/bin/felio-entrypoint

ENTRYPOINT ["felio-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
