#!/bin/sh
set -e

# Only the API container runs migrations, so workers never race on schema changes.
if [ "$1" = "php-fpm" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction
fi

exec "$@"
