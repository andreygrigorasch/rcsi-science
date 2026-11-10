#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction
grep -q '^APP_KEY=base64:' .env || php artisan key:generate

exec php artisan serve --host=0.0.0.0 --port=8000
