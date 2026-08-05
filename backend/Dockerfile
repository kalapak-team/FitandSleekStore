FROM php:8.2-fpm-bookworm

# Keep Debian libpq-dev for PHP — do NOT add PGDG apt (breaks libldap / libpq resolution).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip nginx openssl \
        libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libavif-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install pdo_pgsql zip gd \
    && rm -f /etc/nginx/sites-enabled/default \
    && rm -rf /var/lib/apt/lists/*

# Neon is Postgres 18; ship a matching pg_dump from the official image (private libs).
# lib path differs by arch (x86_64-linux-gnu vs aarch64-linux-gnu on Apple Silicon).
RUN mkdir -p /opt/pg18/bin /opt/pg18/lib
COPY --from=postgres:18-bookworm /usr/lib/postgresql/18/bin/pg_dump /opt/pg18/bin/pg_dump
COPY --from=postgres:18-bookworm /usr/lib/postgresql/18/bin/pg_restore /opt/pg18/bin/pg_restore
RUN --mount=type=bind,from=postgres:18-bookworm,source=/usr/lib,target=/pg18lib \
    set -eux; \
    copied=0; \
    for dir in x86_64-linux-gnu aarch64-linux-gnu; do \
      if [ -e "/pg18lib/${dir}/libpq.so.5" ]; then \
        cp -a "/pg18lib/${dir}"/libpq.so.5* /opt/pg18/lib/; \
        copied=1; \
        break; \
      fi; \
    done; \
    test "$copied" = 1; \
    chmod +x /opt/pg18/bin/pg_dump /opt/pg18/bin/pg_restore

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
