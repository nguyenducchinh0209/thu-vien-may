# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.3-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/library.ini
COPY docker/start.sh /usr/local/bin/library-start
RUN chmod 755 /usr/local/bin/library-start \
    && mkdir -p bootstrap/cache storage/app/public storage/app/private \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && ln -s ../storage/app/public /var/www/html/public/storage \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && runuser -u www-data -- php artisan package:discover --no-interaction

ENTRYPOINT ["library-start"]
CMD ["apache2-foreground"]
