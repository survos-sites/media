release: php bin/console cache:clear && php bin/console doctrine:migrations:migrate -n --allow-no-migration
web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/
download: php -d memory_limit=768M bin/console messenger:consume asset.download --time-limit=3600 --memory-limit=640M
analyze: php -d memory_limit=768M bin/console messenger:consume asset.analyze --time-limit=3600 --memory-limit=640M
