#!/usr/bin/env bash
# P1.1 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Unit/Registry/PermissionCacheTest.php
  tests/Unit/Registry/SubjectIdentityTest.php
  tests/Unit/Registry/EffectivePermissionResolverTest.php
  tests/Unit/Support/ScopedRoleCacheTest.php
  tests/Feature/DirectGrantMorphMapTest.php
  tests/Feature/DirectGrantPanelChangeCacheTest.php
  tests/Feature/DirectGrantGrantableMoveCacheTest.php
  tests/Feature/PermissionCacheEpochInvalidationTest.php
  tests/Feature/PermissionCacheEpochLockWarningTest.php
  tests/Feature/Context/ContextGuardCacheEpochTest.php
)

echo "== P1.1 core validation =="
"${PEST[@]}"

echo "== P1.1 Redis cross-process (optional) =="
if ! php -r 'exit(extension_loaded("redis") ? 0 : 1);'; then
  echo "Redis verdict: unavailable (ext-redis is not installed; optional P1.1 lane not verified)"
elif ! php -r 'try { $redis = new Redis(); $redis->connect(getenv("REDIS_HOST") ?: "127.0.0.1", (int) (getenv("REDIS_PORT") ?: 6379), 1); exit(0); } catch (Throwable $e) { exit(1); }'; then
  echo "Redis verdict: unavailable at ${REDIS_HOST:-127.0.0.1}:${REDIS_PORT:-6379} (optional P1.1 lane not verified)"
elif vendor/bin/pest --fail-on-skipped tests/Feature/PermissionCacheCrossProcessRaceTest.php; then
  echo "Redis verdict: available and cross-process test passed"
else
  echo "Redis verdict: test failed or skipped unexpectedly — fix before closing P1.1" >&2
  exit 1
fi

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
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P1.1"
