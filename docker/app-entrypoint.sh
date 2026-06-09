#!/usr/bin/env bash
# Boot the Laravel app for E2E:
#   1. Wait for the Stripe CLI to publish its webhook signing secret on the
#      shared volume, then export it so constructEvent() accepts forwarded events.
#   2. Prepare a fresh SQLite DB (migrate + seed demo accounts & catalog).
#   3. Run the queue worker (webhooks are processed via ProcessStripeEvent) and
#      the dev server side by side.
#
# Env (APP_KEY, STRIPE_KEY/SECRET, APP_URL, ...) comes from compose `env_file`.
# Laravel's dotenv is immutable, so the exported STRIPE_WEBHOOK_SECRET wins as
# long as .env.e2e does NOT also define it.
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

# Fresh SQLite database file on every boot for deterministic runs.
mkdir -p database
: > database/database.sqlite

echo "[entrypoint] migrating + seeding ..."
php artisan migrate:fresh --force --seed --no-interaction

# No config:cache — config/payment.php reads env() at runtime so the exported
# webhook secret is picked up.
php artisan config:clear

echo "[entrypoint] starting queue worker + http server on :8000"
php artisan queue:work --tries=3 --sleep=1 --backoff=3 &
exec php artisan serve --host=0.0.0.0 --port=8000
