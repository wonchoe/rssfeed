#!/bin/sh
set -e

# Ensure storage directories are present and writable
mkdir -p /var/www/storage/framework/{sessions,views,cache} \
         /var/www/storage/logs \
         /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

cd /var/www

# Cache config/routes/views for production performance
php artisan config:cache   --no-interaction
php artisan route:cache    --no-interaction
php artisan view:cache     --no-interaction
php artisan event:cache    --no-interaction

# Run DB migrations (non-destructive, idempotent)
php artisan migrate --force --no-interaction

echo "→ Starting supervisord …"
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
