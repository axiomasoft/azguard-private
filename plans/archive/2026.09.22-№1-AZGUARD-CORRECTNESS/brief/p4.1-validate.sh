#!/usr/bin/env bash
# P4.1 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Feature/CurrentPanelLifecycleTest.php
  tests/Feature/AuthModelScopedRoleGuardTest.php
  tests/Feature/SetCurrentPanelMiddlewareTest.php
  tests/Feature/JobProcessingPanelResetTest.php
  tests/Feature/Context/ContextScopingTest.php
)

echo "== P4.1 validation =="
"${PEST[@]}"

echo "== Pint (changed PHP) =="
mapfile -t changed < <(git diff --name-only -- '*.php' | while read -r f; do [[ -f "$f" ]] && printf '%s\n' "$f"; done)
p4_files=(
  packages/core/src/Runtime/CurrentPanelState.php
  packages/core/src/AzGuardManager.php
  packages/core/src/AzGuardServiceProvider.php
  packages/core/src/Http/Middleware/SetCurrentPanel.php
  packages/core/src/Concerns/HasScopedRoles.php
  tests/Feature/CurrentPanelLifecycleTest.php
  tests/Feature/AuthModelScopedRoleGuardTest.php
  tests/Feature/SetCurrentPanelMiddlewareTest.php
  tests/Pest.php
)
existing=()
for f in "${p4_files[@]}"; do
  [[ -f "$f" ]] && existing+=("$f")
done
if ((${#existing[@]})); then
  vendor/bin/pint --test "${existing[@]}"
else
  echo "(no P4.1 PHP files)"
fi

git diff --check

echo "== GREEN: append journal + finalize P4.1 =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P4.1"
