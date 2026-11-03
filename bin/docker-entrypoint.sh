#!/bin/sh
# Container entrypoint: make the attachment directory writable, then hand over.
#
# `var/storage` (storage.driver = local) is bind-mounted from the host by both
# compose files. Docker creates a missing host directory as root, and PHP-FPM
# runs its workers as www-data, so without this the first upload fails on a
# permission error. The `chown` is not recursive on purpose: a large attachment
# tree must not slow down every container start, and the files inside are created
# by www-data anyway.
set -e

storage_dir="${BCMDB_STORAGE_PATH:-/var/www/html/var/storage}"
mkdir -p "$storage_dir"
if [ "$(id -u)" = "0" ]; then
    chown www-data:www-data "$storage_dir" 2>/dev/null || true
fi

exec "$@"
