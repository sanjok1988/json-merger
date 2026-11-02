#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if [[ ! -f "vendor/autoload.php" ]]; then
    echo "Dependencies are missing. Run: composer install" >&2
    exit 1
fi

exec php -S 127.0.0.1:8080 demo/router.php "$@"
