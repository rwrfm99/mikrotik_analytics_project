FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libonig-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring zip bcmath pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY backend/ ./
RUN composer install --no-interaction --prefer-dist \
    && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm"]
