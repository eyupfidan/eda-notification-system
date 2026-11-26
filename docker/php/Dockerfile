FROM php:8.4-fpm-alpine

# Runtime libraries + PHP extensions (pgsql, redis, pcntl for queue workers)
RUN apk add --no-cache libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl intl zip bcmath opcache \
    && pecl install redis pcov \
    && docker-php-ext-enable redis pcov \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first to leverage the Docker layer cache
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
# Configuration comes from container environment variables (docker-compose.yml);
# an empty .env keeps dotenv from probing a missing file.
RUN touch .env \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
