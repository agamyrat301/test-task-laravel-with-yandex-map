#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "$(grep '^APP_KEY=.\+' .env)" ]; then
    php artisan key:generate --force
fi

php artisan migrate --force

exec "$@"
