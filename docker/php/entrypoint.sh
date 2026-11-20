#!/bin/sh
set -e

# Tạo cấu trúc thư mục storage nếu chưa có (named volume mới)
mkdir -p storage/framework/views \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/testing \
         storage/logs \
         storage/app/private \
         bootstrap/cache

chmod -R 775 storage bootstrap/cache

# Cài vendor vào Linux named volume nếu chưa có
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] vendor not found, running composer install..."
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi

# Xóa config cache cũ (tránh bị bake từ .env sai)
php artisan config:clear

# Cache route/view/event nền sau khi FPM đã lên
(
    sleep 3
    php artisan route:cache 2>&1 | sed 's/^/[cache] /'
    php artisan view:cache  2>&1 | sed 's/^/[cache] /'
    php artisan event:cache 2>&1 | sed 's/^/[cache] /'
) &

exec php-fpm
