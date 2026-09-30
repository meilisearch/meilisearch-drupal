#!/usr/bin/env bash
# One-shot test run: rebuilds the tests image with the current code and runs
# PHPUnit against a real Meilisearch. Extra arguments go to PHPUnit, with
# paths relative to the module root (e.g. tests/src/Unit).
set -euo pipefail
cd "$(dirname "$0")/.."
args=()
for a in "$@"; do
  if [ -e "$a" ]; then args+=("web/modules/custom/meilisearch/$a"); else args+=("$a"); fi
done
docker compose build -q tests
docker compose up -d --wait meilisearch
docker compose run --rm tests web/modules/custom/meilisearch/scripts/phpunit ${args[@]+"${args[@]}"}
