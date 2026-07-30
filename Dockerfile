FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        pdo_mysql \
        pdo_sqlite \
        zip

COPY .docker/entrypoint.sh /usr/local/bin/felio-entrypoint
RUN chmod +x /usr/local/bin/felio-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["felio-entrypoint"]
CMD ["php-fpm"]
