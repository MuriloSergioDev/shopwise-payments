#!/usr/bin/env sh
# Runs a PHP command with host PHP when available, otherwise inside the shopwise-php:base image.
# Usage: scripts/php.sh vendor/bin/pint --test app/Foo.php
set -e
if command -v php >/dev/null 2>&1; then
  exec php "$@"
fi
dir=$(pwd -W 2>/dev/null || pwd)
MSYS_NO_PATHCONV=1 exec docker run --rm -v "$dir:/app" -w /app shopwise-php:base php "$@"
