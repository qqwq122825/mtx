#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
PHP_BINARY="${PHP_BINARY:-php}"
PORT="${PORT:-8787}"
exec "$PHP_BINARY" -d upload_max_filesize=256M -d post_max_size=260M -d memory_limit=256M -d max_execution_time=300 -S "127.0.0.1:$PORT" -t public public/router.php
