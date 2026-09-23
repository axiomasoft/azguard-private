#!/usr/bin/env bash
# P1.2 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Unit/Registry/PermissionSetTest.php
  tests/Unit/Registry/PermissionCacheTest.php
  tests/Unit/Registry/EffectivePermissionResolverTest.php
  tests/Feature/DirectGrantSourceTest.php
  tests/Feature/Context/ContextPermissionLayerTest.php
  tests/Feature/CustomGrantSourceTest.php
  tests/Unit/ApiBoundaryTest.php
  tests/Unit/Context/MergeStrategyTest.php
)

echo "== P1.2 core validation =="
"${PEST[@]}"

echo "== PHPStan (affected sources) =="
vendor/bin/phpstan analyse --memory-limit=1G \
  packages/core/src/Registry/Values/PermissionSet.php \
  packages/core/src/Registry/Resolver/PermissionCache.php \
  packages/core/src/Registry/Sources/DirectGrantSource.php \
  packages/context/src/ContextPermissionLayer.php

echo "== Pint (changed PHP) =="
mapfile -t changed < <(git diff --name-only -- '*.php' | tr -d '\r')
existing=()
for f in "${changed[@]}"; do
  [[ -f "$f" ]] && existing+=("$f")
done
if ((${#existing[@]})); then
  vendor/bin/pint --test "${existing[@]}"
else
  echo "(no existing unstaged PHP changes)"
fi

git diff --check

echo "== GREEN: append journal + finalize =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P1.2"
