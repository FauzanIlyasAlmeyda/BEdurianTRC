#!/bin/sh
set -eu

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage/framework bootstrap/cache
chown www-data:www-data storage/app/public

if [ ! -L public/storage ]; then
    php artisan storage:link --force
fi

exec "$@"
