# Development image for the EORA API (B0). Not a production image (production build is a later slice).
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip git curl \
    && docker-php-ext-install pdo_pgsql zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize && php artisan package:discover --ansi

EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 CMD curl -fsS http://127.0.0.1:8000/api/v1/health || exit 1
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
