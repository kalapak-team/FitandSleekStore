FROM php:8.2-fpm

# PGDG client so pg_dump matches Neon Postgres 18+ (Debian default client is too old).
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip nginx openssl postgresql-client-18 \
        libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql zip gd \
    && rm -f /etc/nginx/sites-enabled/default \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Install PHP deps before copying the full tree (better layer cache).
COPY composer.json composer.lock ./

# GitHub codeload occasionally returns HTTP 400 on Render — retry, then prefer-source.
RUN set -eux; \
    composer install --no-interaction --no-dev --optimize-autoloader --no-scripts \
    || (composer clear-cache \
        && composer install --no-interaction --no-dev --optimize-autoloader --no-scripts) \
    || (composer clear-cache \
        && composer install --no-interaction --no-dev --optimize-autoloader --no-scripts --prefer-source)

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php-fpm/zz-custom.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["web"]
