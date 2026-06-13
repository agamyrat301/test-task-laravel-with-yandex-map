#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "$(grep '^APP_KEY=.\+' .env)" ]; then
    php artisan key:generate --force
fi

migrate_out=$(php artisan migrate --force 2>&1)
migrate_exit=$?
echo "$migrate_out"
if [ $migrate_exit -ne 0 ]; then
    # Tolerate "table already exists" — happens when app and queue containers
    # both run this entrypoint simultaneously and race to create the migrations table.
    echo "$migrate_out" | grep -q "already exists" || exit $migrate_exit
fi

exec "$@"
