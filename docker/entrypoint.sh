#!/bin/sh
# Boot sequence for the Linkweaver container.
set -e

: "${PORT:=8080}"

# Render assigns a port at runtime, so it is substituted rather than baked in.
sed -i "s/listen \${PORT};/listen ${PORT};/" /etc/nginx/nginx.conf
mkdir -p /run/nginx

if [ -z "${APP_KEY}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'." >&2
    exit 1
fi

echo "==> Waiting for the database"
tries=0
until php -r 'new PDO(
        sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")),
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD")
    );' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "FATAL: database unreachable after 30 attempts." >&2
        exit 1
    fi
    sleep 2
done

echo "==> Running migrations"
php artisan migrate --force

# The demo is the landing page's call to action, so a fresh container must come
# up with one. The seeder is idempotent.
if [ "${LINKWEAVER_SEED_DEMO:-true}" = "true" ]; then
    echo "==> Seeding the demo project"
    php artisan db:seed --class=DemoProjectSeeder --force
fi

echo "==> Caching configuration"
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "==> Starting nginx, php-fpm and the queue worker"
exec "$@"
