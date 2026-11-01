FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize

# Dev/test image (not used at runtime): PHP CLI + composer, run via `make` with the source mounted.
FROM php:8.3-cli AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app

FROM php:8.3-fpm-alpine
RUN docker-php-ext-install pdo_mysql opcache
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www/html
COPY --from=vendor /app .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p var/uploads \
    && chown -R www-data:www-data var
USER www-data
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]