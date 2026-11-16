#!/bin/sh
set -eu

install -d -o www-data -g www-data -m 0755 \
    storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache

exec docker-php-entrypoint "$@"
