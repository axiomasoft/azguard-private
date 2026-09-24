#!/usr/bin/env bash
# P4.2 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Unit/Support/StrictPanelsTest.php
  tests/Feature/CheckAccessMiddlewareTest.php
  tests/Feature/AuthorizerExtendedTest.php
  tests/Feature/AuthorizerPanelResolutionTest.php
  tests/Feature/DoctorCommandTest.php
  tests/Unit/Registry/EffectivePermissionResolverTest.php
  tests/Unit/Permissions/PermissionGrammarTest.php
  tests/Unit/Permissions/CatalogKeyMatcherTest.php
  tests/Unit/Support/PanelTest.php
  tests/Feature/CurrentPanelLifecycleTest.php
  tests/Feature/AuthModelScopedRoleGuardTest.php
  tests/Feature/SetCurrentPanelMiddlewareTest.php
  tests/Feature/JobProcessingPanelResetTest.php
  tests/Feature/Filament/MultiPanelIsolationTest.php
)

echo "== P4.2 validation =="
"${PEST[@]}"

echo "== Pint (changed PHP) =="
mapfile -t changed < <(git diff --name-only -- '*.php' | while read -r f; do [[ -f "$f" ]] && printf '%s\n' "$f"; done)
p4_files=(
  packages/core/src/Permissions/PermissionGrammar.php
  packages/core/src/Permissions/CatalogKeyMatcher.php
  packages/core/src/Permissions/PermissionName.php
  packages/core/src/Panels/PanelResolver.php
  packages/core/src/Panels/Panel.php
  packages/core/src/Http/Middleware/CheckAccess.php
  packages/core/src/Guard/Authorizer.php
  packages/core/src/Guard/AzGuardDiagnostics.php
  packages/core/src/Registry/Resolver/EffectivePermissionResolver.php
  packages/core/src/Configuration/Config.php
  packages/core/src/Exceptions/PanelIdTooLongException.php
  packages/core/src/Exceptions/MissingPermissionAttributeException.php
  packages/core/src/Exceptions/InvalidPermissionSyntaxException.php
  packages/core/config/az-guard.php
  tests/Unit/Support/StrictPanelsTest.php
  tests/Unit/Support/PanelTest.php
  tests/Unit/Permissions/PermissionGrammarTest.php
  tests/Unit/Permissions/CatalogKeyMatcherTest.php
  tests/Feature/CheckAccessMiddlewareTest.php
  tests/Feature/AuthorizerExtendedTest.php
  tests/Feature/DoctorCommandTest.php
  tests/Feature/Filament/MultiPanelIsolationTest.php
)
existing=()
for f in "${p4_files[@]}"; do
  [[ -f "$f" ]] && existing+=("$f")
done
if ((${#existing[@]})); then
  vendor/bin/pint --test "${existing[@]}"
else
  echo "(no P4.2 PHP files)"
fi

git diff --check

echo "== GREEN: append journal + finalize P4.2 =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P4.2"
