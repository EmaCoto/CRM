#!/bin/sh
set -e

mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chmod -R ug+rw storage bootstrap/cache || true

exec "$@"
