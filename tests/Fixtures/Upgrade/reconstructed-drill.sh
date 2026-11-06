#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
echo 'RECONSTRUCTED candidate schema only; this is not a published baseline qualification.'
cd "$root"
exec vendor/bin/pest tests/Feature/Storage/RestoreStateResetTest.php --filter='rehearses a reconstructed candidate schema'
