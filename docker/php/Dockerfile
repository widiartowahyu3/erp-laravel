# syntax=docker/dockerfile:1

# ------------------------------------------------------------------------------
# Base PHP-FPM image with required extensions & hardening
# ------------------------------------------------------------------------------
FROM php:8.4.27-fpm-alpine AS base

# Install runtime libraries
RUN apk add --no-cache \
    icu-libs \
    libzip \
    libstdc++

# Compile required PHP extensions using official mechanism
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    icu-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        intl \
        bcmath \
        zip \
        opcache \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# Setup non-root user matching WSL UID/GID (default 1000)
ARG UID=1000
ARG GID=1000

RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g ${GID} -S appuser \
    && adduser -u ${UID} -S -D -G appuser -h /var/www/html -s /bin/sh appuser

# Configure PHP & PHP-FPM
COPY docker/php/conf/php-common.ini /usr/local/etc/php/conf.d/10-common.ini
COPY docker/php/conf/php-fpm-hardening.conf /usr/local/etc/php-fpm.d/99-hardening.conf

# Prepare application directory
WORKDIR /var/www/html
RUN chown -R appuser:appuser /var/www/html

# ------------------------------------------------------------------------------
# Dev Stage: includes composer, git, unzip, and development opcache
# ------------------------------------------------------------------------------
FROM base AS dev

# Install development command-line tools
RUN apk add --no-cache \
    git \
    unzip \
    bash

# Copy Composer binary from pinned official image
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

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

USER appuser

EXPOSE 9000
CMD ["php-fpm", "-F"]
