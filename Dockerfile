FROM composer:2 AS composer

FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        nginx \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-duriantrace.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-duriantrace.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/duriantrace-entrypoint

RUN chmod 0755 /usr/local/bin/duriantrace-entrypoint \
    && rm -f /etc/nginx/sites-enabled/default

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/duriantrace-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
