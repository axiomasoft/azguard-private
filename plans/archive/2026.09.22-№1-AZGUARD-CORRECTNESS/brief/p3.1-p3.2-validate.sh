#!/usr/bin/env bash
# P3.1–P3.2 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Feature/ConfiguredModelsTest.php
  tests/Feature/ExtensionSwapTest.php
  tests/Feature/DatabaseRoleGrantSourceTest.php
  tests/Feature/HasDirectGrantsTest.php
  tests/Feature/ScopedRolePanelIsolationTest.php
  tests/Feature/Filament/ConfiguredModelResourceTest.php
  tests/Feature/DoctorCommandTest.php
  tests/Feature/Filament/ResourceEnforcementTest.php
)

echo "== P3.1–P3.2 validation =="
"${PEST[@]}"

echo "== Pint (changed PHP) =="
mapfile -t changed < <(git diff --name-only -- '*.php' | while read -r f; do [[ -f "$f" ]] && printf '%s\n' "$f"; done)
if ((${#changed[@]})); then
  vendor/bin/pint --test "${changed[@]}"
else
  echo "(no existing unstaged PHP changes)"
fi

git diff --check

echo "== GREEN: append journal + finalize P3.1 then P3.2 =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P3.1"
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P3.2"
