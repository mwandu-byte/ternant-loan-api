#!/bin/sh
set -e

# Config/route caching needs the real environment (DB credentials, JWT
# secret, mail settings, etc.), which only exists at container runtime via
# the mounted .env / injected env vars — never baked into the image.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
