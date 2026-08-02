#!/usr/bin/env bash
# One-shot migrate using serviceop_migrate credentials supplied ONLY for this process.
# Does not write credentials into the app's standing .env.
#
# Usage (CI / laptop with secrets in the environment for this job only):
#   export DB_HOST=...
#   export DB_PORT=3306
#   export DB_DATABASE=...
#   export DB_MIGRATE_USERNAME=serviceop_migrate
#   export DB_MIGRATE_PASSWORD='…'
#   ./scripts/deploy/migrate_as_migrate_user.sh
#
# Requires bash + php in PATH; run from repo root or set BACKEND_DIR.

set -euo pipefail

BACKEND_DIR="${BACKEND_DIR:-backend}"
cd "$BACKEND_DIR"

: "${DB_HOST:?DB_HOST required}"
: "${DB_DATABASE:?DB_DATABASE required}"
: "${DB_MIGRATE_USERNAME:?DB_MIGRATE_USERNAME required (serviceop_migrate)}"
: "${DB_MIGRATE_PASSWORD:?DB_MIGRATE_PASSWORD required}"

export DB_CONNECTION="${DB_CONNECTION:-mysql}"
export DB_PORT="${DB_PORT:-3306}"
export DB_USERNAME="$DB_MIGRATE_USERNAME"
export DB_PASSWORD="$DB_MIGRATE_PASSWORD"

echo "==> migrate as ${DB_USERNAME}@${DB_HOST} / ${DB_DATABASE}"
php artisan config:clear
php artisan migrate --force
php artisan db:verify-least-privilege --identity=migrate
echo "==> migrate job finished; migrate credentials were process-env only"
