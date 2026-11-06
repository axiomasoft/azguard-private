#!/usr/bin/env bash
# Harness test double only. Never add this to the publication inventory.
set -euo pipefail
php -r '
    $checks = array_fill_keys(["baseline_installed", "seeded_roles_grants_fields_morph_audit", "target_installed",
        "migrations_twice", "versions_match", "doctor", "allow_deny_parity", "state_reset"], true);
    if (file_get_contents(getenv("AZGUARD_UPGRADE_CORE")) === "missing-check") { unset($checks["doctor"]); }
    file_put_contents(getenv("AZGUARD_UPGRADE_DIR")."/result.json", json_encode([
        "baseline" => "mock", "target" => getenv("AZGUARD_UPGRADE_TARGET"), "checks" => $checks,
    ], JSON_THROW_ON_ERROR));
'
