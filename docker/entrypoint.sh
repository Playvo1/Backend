#!/bin/sh
set -e

# Render routes traffic to $PORT; Apache listens on 80 by default.
PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Render sets RENDER_EXTERNAL_URL (https://<service>.onrender.com) automatically.
export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=InitialAdminSeeder --force
php artisan storage:link || true

# Runs scheduled commands (bookings:expire every minute) alongside the web server.
php artisan schedule:work &

exec apache2-foreground
