#!/usr/bin/env bash
# P5.2 validation gate — run from repo root after implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PEST=(vendor/bin/pest
  tests/Feature/MakeGuardPanelCommandTest.php
  tests/Feature/MakeGuardDomainCommandTest.php
  tests/Feature/DoctorCommandTest.php
)

echo "== P5.2 validation =="
"${PEST[@]}"

echo "== Pint (P5.2 PHP) =="
p5_files=(
  packages/core/src/Scaffold/GuardScaffoldGenerator.php
  packages/core/src/Commands/MakeGuardPanelCommand.php
  packages/core/src/Commands/MakeGuardDomainCommand.php
  packages/core/src/Guard/AzGuardDiagnostics.php
  tests/Feature/MakeGuardPanelCommandTest.php
  tests/Feature/MakeGuardDomainCommandTest.php
)
existing=()
for f in "${p5_files[@]}"; do
  [[ -f "$f" ]] && existing+=("$f")
done
if ((${#existing[@]})); then
  vendor/bin/pint --test "${existing[@]}"
else
  echo "(no P5.2 PHP files)"
fi

git diff --check

echo "== GREEN: append journal + finalize P5.2 =="
echo "python3 ../swissknifeman/packages/task/scripts/plan-work.py finalize \\"
echo "  --plan-dir plans/2026.09.22-№1-AZGUARD-CORRECTNESS --item P5.2"
