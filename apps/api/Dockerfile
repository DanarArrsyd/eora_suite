# Development image for the EORA API (B0). Not a production image (production build is a later slice).
FROM php:8.4-cli-bookworm

# libpq comes from the official PostgreSQL repository (apt.postgresql.org): pdo_pgsql must be compiled against
# libpq >= 17 so prepared statements are closed at protocol level and work through PgBouncer transaction pooling
# (D-056 P-05; Debian bookworm ships libpq 15, which closes them with SQL DEALLOCATE and aborts the transaction).
# postgresql-client provides psql for the idempotent database bootstrap (database/bootstrap).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
       > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev postgresql-client-18 libzip-dev libicu-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip intl bcmath \
    && libpq_major=$(php -i | sed -n 's/^PostgreSQL(libpq) Version => \([0-9]*\).*/\1/p' | head -n 1) \
    && test "$libpq_major" -ge 17 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# Same locked versions either way: GitHub's archive endpoint (codeload) sometimes answers 404 for a zip it serves
# minutes later, so a failed dist install is retried once and then installed from the locked git commits.
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader \
    || (sleep 15 && composer install --no-interaction --prefer-dist --no-scripts --no-autoloader) \
    || composer install --no-interaction --prefer-source --no-scripts --no-autoloader
COPY . .
# Configuration comes from the container environment (compose). .dockerignore keeps the host .env out;
# an empty .env stops phpdotenv probing a missing file (PHPUnit reports that as a warning). Never put secrets here.
RUN touch .env
RUN composer dump-autoload --optimize && php artisan package:discover --ansi

EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 CMD curl -fsS http://127.0.0.1:8000/api/v1/health || exit 1
# --no-reload: without it, `artisan serve` strips non-passthrough variables (APP_NAME, DB_*, REDIS_*) from the
# worker whenever a .env file exists, expecting to re-read them from .env. Here config comes from the environment.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
