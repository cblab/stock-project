#!/usr/bin/env sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)"
TEST_PATH="${1:-}"

cd "$REPO_ROOT"

COMPOSE_ARGS="
--env-file docker/prod.env
-p stock-project-dev
-f compose.yaml
-f docker/compose.dev.yml
"

docker compose $COMPOSE_ARGS --profile test run --rm test-db-setup

if [ -n "$TEST_PATH" ]; then
  docker compose $COMPOSE_ARGS --profile test run --rm web-test sh -lc \
    "composer install --no-interaction --prefer-dist && APP_ENV=test php bin/phpunit \"$TEST_PATH\""
else
  docker compose $COMPOSE_ARGS --profile test run --rm web-test sh -lc \
    "composer install --no-interaction --prefer-dist && APP_ENV=test php bin/phpunit"
fi
