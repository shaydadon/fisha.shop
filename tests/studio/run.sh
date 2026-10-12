#!/usr/bin/env bash
# Fisha Studio integration tests. Run from anywhere in Git Bash:  bash tests/studio/run.sh [name-filter]
set -o pipefail
cd "$(dirname "$0")/../.." || exit 1
MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp eval-file tests/studio/run.php "$@" 2>&1 \
	| grep -v -e 'Container fisha' -e 'level=warning'
