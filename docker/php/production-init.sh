#!/bin/sh
set -eu
cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/views \
    storage/framework/cache/data storage/framework/sessions storage/logs
chown -R www-data:www-data storage
exec su -s /bin/sh www-data -c '
    set -eu
    php artisan migrate --force --no-interaction
    php /usr/local/bin/production-bootstrap.php
    echo "Production initialization completed."
'
