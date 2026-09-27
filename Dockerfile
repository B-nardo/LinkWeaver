# Linkweaver API — production image.
#
# One container runs nginx, php-fpm and a single queue worker under Supervisor.
# That is unusual, and deliberate: spec 9 targets free hosting, where Render's
# free tier gives you exactly one service and no separate worker. The queue is
# not optional here — the whole pipeline is queued jobs, so an API container
# with no worker would accept projects and never process them.
#
# Built for linux/amd64 and linux/arm64 (Oracle Cloud's Always Free tier is
# ARM). Every base image below is multi-arch, and nothing is compiled for a
# specific architecture.

# ------------------------------------------------------------------------------
# Stage 1 — PHP dependencies
# ------------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Copied first so the dependency layer is cached independently of app code.
COPY backend/composer.json backend/composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY backend/ ./

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ------------------------------------------------------------------------------
# Stage 2 — Runtime
# ------------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        mysql-client \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        opcache \
        pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# opcache is the single biggest performance difference on a small container.
# `validate_timestamps=0` is safe because the code never changes inside a
# running image.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/linkweaver.ini

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# php-fpm's own user owns only what must be writable. Application code stays
# read-only to the process serving it.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Render supplies PORT; nginx.conf reads it. 8080 is the local default.
ENV PORT=8080
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/api/health") ? 0 : 1);'

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
