#!/bin/sh
set -eu

# FPM clears the environment for workers, so the config cache must exist before it starts.
if [ "${1:-}" = "php-fpm" ]; then
    php artisan package:discover --ansi
    php artisan optimize
fi

exec "$@"
