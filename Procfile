web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/
download: bin/console messenger:consume asset.download --time-limit=3600 --memory-limit=256M
analyze: bin/console messenger:consume asset.analyze --time-limit=3600 --memory-limit=256M
