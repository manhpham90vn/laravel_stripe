#!/usr/bin/env bash

set -euo pipefail
cd /app

SECRET_FILE="/shared/webhook_secret"

echo "[entrypoint] waiting for ${SECRET_FILE} (stripe listen --print-secret) ..."
for _ in $(seq 1 60); do
    [ -s "$SECRET_FILE" ] && break
    sleep 2
done

if [ -s "$SECRET_FILE" ]; then
    export STRIPE_WEBHOOK_SECRET="$(tr -d '[:space:]' < "$SECRET_FILE")"
    echo "[entrypoint] loaded STRIPE_WEBHOOK_SECRET (${STRIPE_WEBHOOK_SECRET:0:10}...)"
else
    echo "[entrypoint] WARNING: ${SECRET_FILE} empty — webhook signature checks will fail (400)."
fi

mkdir -p database
: > database/database.sqlite

echo "[entrypoint] migrating + seeding ..."
php artisan migrate:fresh --force --seed --no-interaction

php artisan config:clear

echo "[entrypoint] starting queue worker + http server on :8000"
php artisan queue:work --tries=3 --sleep=1 --backoff=3 &

export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-5}"
exec php artisan serve --host=0.0.0.0 --port=8000
