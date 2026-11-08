# syntax=docker/dockerfile:1

# ------------------------------------------------------------------------------
# Base PHP-FPM image with required extensions & hardening
# ------------------------------------------------------------------------------
FROM php:8.5.12-fpm-alpine@sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e AS base

# Install runtime libraries
RUN apk add --no-cache \
    icu-libs \
    libzip \
    libstdc++

# Compile required PHP extensions using official mechanism (OPcache is built-in on PHP 8.5)
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    icu-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        intl \
        bcmath \
        zip \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# Setup non-root user matching WSL UID/GID (default 1000)
ARG UID=1000
ARG GID=1000

RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g ${GID} -S appuser \
    && adduser -u ${UID} -S -D -G appuser -h /var/www/html -s /bin/sh appuser \
    && sed -i 's/^user = www-data/user = appuser/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = www-data/group = appuser/' /usr/local/etc/php-fpm.d/www.conf

# Configure PHP & PHP-FPM
COPY docker/php/conf/php-common.ini /usr/local/etc/php/conf.d/10-common.ini
COPY docker/php/conf/php-fpm-hardening.conf /usr/local/etc/php-fpm.d/zz-hardening.conf

# Prepare application directory
WORKDIR /var/www/html
RUN chown -R appuser:appuser /var/www/html

# ------------------------------------------------------------------------------
# Dev Stage: includes composer, git, unzip, bash, fcgi, and development opcache
# ------------------------------------------------------------------------------
FROM base AS dev

# Install development command-line tools
RUN apk add --no-cache \
    git \
    unzip \
    bash \
    fcgi

# Copy Composer binary from pinned official image
COPY --from=composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

# CLI-specific configuration
COPY docker/php/conf/php-cli.ini /usr/local/etc/php/conf.d/20-cli.ini

# Opcache development configuration (validate_timestamps enabled)
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=0'; \
} > /usr/local/etc/php/conf.d/30-opcache.ini

# Create composer cache directory for non-root user
RUN mkdir -p /var/www/.composer /tmp/composer \
    && chown -R appuser:appuser /var/www/.composer /tmp/composer

ENV COMPOSER_HOME=/tmp/composer

USER appuser

EXPOSE 9000
CMD ["php-fpm", "-F"]

# ------------------------------------------------------------------------------
# Build Stages: production vendor tree and compiled frontend assets
# ------------------------------------------------------------------------------
FROM base AS app-build

COPY --from=composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

COPY src/ ./
RUN composer dump-autoload --no-dev --no-scripts --optimize --no-interaction \
    && mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

FROM node:24.22.1-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53 AS assets

WORKDIR /var/www/html

COPY src/package.json src/package-lock.json src/.npmrc ./
RUN npm ci --no-audit --no-fund

COPY src/ ./
RUN npm run build

# ------------------------------------------------------------------------------
# Prod Stage: hardened for production, without dev tools
# ------------------------------------------------------------------------------
FROM base AS prod

# Opcache production configuration (validate_timestamps disabled)
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.validate_timestamps=0'; \
} > /usr/local/etc/php/conf.d/30-opcache.ini

# Application code stays root-owned so a compromised worker cannot modify it
COPY --from=app-build /var/www/html ./
COPY --from=assets /var/www/html/public/build ./public/build
COPY --chmod=0755 docker/php/prod-entrypoint.sh /usr/local/bin/prod-entrypoint

USER appuser

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/prod-entrypoint"]
CMD ["php-fpm", "-F"]

# ------------------------------------------------------------------------------
# Web Stage: production nginx with the public assets baked in
# ------------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.31.7-alpine@sha256:b5047b9b76845eac124358373c6852377c52e4b05834a47647e955dd91cb8f1e AS web

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY src/public/ /var/www/html/public/
COPY --from=assets /var/www/html/public/build /var/www/html/public/build

EXPOSE 8080 8443
