#!/bin/sh
set -e

# Render routes traffic to $PORT; Apache listens on 80 by default.
PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Render sets RENDER_EXTERNAL_URL (https://<service>.onrender.com) automatically.
export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"

# Render secret files are readable by root only, but Apache runs PHP as www-data,
# so web requests couldn't load the DB CA certificate. Copy it somewhere readable.
if [ -n "$MYSQL_ATTR_SSL_CA" ] && [ -f "$MYSQL_ATTR_SSL_CA" ]; then
    cp "$MYSQL_ATTR_SSL_CA" /usr/local/share/db-ca.pem
    chmod 644 /usr/local/share/db-ca.pem
    export MYSQL_ATTR_SSL_CA=/usr/local/share/db-ca.pem
fi

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
