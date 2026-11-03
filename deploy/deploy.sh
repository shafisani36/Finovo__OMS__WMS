#!/usr/bin/env bash
# Release the latest code of the main branch on this server. Run as the deploy user from /var/www/finovo:
#   ./deploy/deploy.sh
# Stops at the first failing step, so a broken build or a failing migration never goes live half done.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

echo "1/6 Getting the latest code"
git pull --ff-only origin main

echo "2/6 Backend packages"
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer audit --no-dev || { echo "composer audit found a security problem: update the package first."; exit 1; }

echo "3/6 Frontend build"
cd ../Frontend
npm ci --no-audit --no-fund
npm run build

echo "4/6 Database changes (the app shows a maintenance page meanwhile)"
cd ../backend
php artisan down --retry=15 --refresh=15
trap 'php artisan up' EXIT
php artisan migrate --force

echo "5/6 Caching configuration, routes, events and views"
php artisan optimize
php artisan storage:link 2>/dev/null || true

echo "6/6 Restarting workers and PHP"
php artisan queue:restart
sudo systemctl reload php8.3-fpm
php artisan up
trap - EXIT

curl -fsS "$(grep -E '^APP_URL=' .env | cut -d= -f2-)/up" > /dev/null && echo "Release is live and healthy."
