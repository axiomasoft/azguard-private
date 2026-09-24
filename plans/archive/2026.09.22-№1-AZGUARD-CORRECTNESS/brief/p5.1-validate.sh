#!/usr/bin/env bash
# P5.1 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Feature/SyncRolesCommandTest.php
  tests/Feature/RoleClassResolutionTest.php
  tests/Unit/Models/RoleGetRoleLogicTest.php
  tests/Feature/DoctorCommandTest.php
  tests/Feature/SuperAdminCommandTest.php
  tests/Feature/RoleAssignmentCommandTest.php
  tests/Feature/PermissionStateRevisionProtocolTest.php
  tests/Feature/ConfiguredModelsTest.php
)

echo "== P5.1 validation =="
"${PEST[@]}"

echo "== Pint (P5.1 PHP) =="
p5_files=(
  packages/core/src/Support/RoleIdentity.php
  packages/core/src/Support/RoleSyncPlanner.php
  packages/core/src/Exceptions/InvalidRoleClassException.php
  packages/core/src/Exceptions/InvalidRoleIdentityException.php
  packages/core/src/Models/Role.php
  packages/core/src/Commands/SyncRolesCommand.php
  packages/core/src/Commands/SuperAdminCommand.php
  packages/core/src/Commands/RoleAssignmentCommand.php
  packages/core/src/Concerns/ResolvesRole.php
  packages/core/src/Concerns/HasRoles.php
  packages/core/src/Guard/AzGuardDiagnostics.php
  tests/Feature/SyncRolesCommandTest.php
  tests/Feature/RoleClassResolutionTest.php
  tests/Unit/Models/RoleGetRoleLogicTest.php
  tests/Feature/DoctorCommandTest.php
  tests/Feature/SuperAdminCommandTest.php
  tests/Stubs/Roles/SalesAdminRole.php
  tests/Stubs/Roles/SupportAdminRole.php
)
existing=()
for f in "${p5_files[@]}"; do
  [[ -f "$f" ]] && existing+=("$f")
done
if ((${#existing[@]})); then
  vendor/bin/pint --test "${existing[@]}"
else
  echo "(no P5.1 PHP files)"
fi

git diff --check

echo "== GREEN: append journal + finalize P5.1 =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P5.1"
