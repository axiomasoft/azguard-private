# Upgrading to 2.0

## Qualification and rollback

The 2026-10-30 publication inventory in `RELEASING.md` did not establish a published 1.0 artifact. It does not
prove that 1.0 was never consumed. No published baseline-to-2.0 upgrade has been qualified by this repository's
upgrade drill: `bash bin/upgrade-drill.sh` fails until the required baselines and executable fixtures are available.
A reconstructed schema or a fresh 2.0 installation is not evidence of a published upgrade. On 2026-11-02 both
Packagist stable and development endpoints returned 404 and both split tag queries required unavailable credentials;
neither observation proves absence. For each baseline
established by a current split and Packagist inventory, rehearse on a fresh disposable copy with the matching
baseline artifact and lock file; retain the artifact identity, commit, tool versions and actual logs. Verify seeded
roles, grants, fields, morph identities and audit rows, migrations run twice, matching core and Filament versions,
Doctor, and representative allow and deny decisions. Do not mark unavailable artifacts as passed.

`bash tests/Fixtures/Upgrade/reconstructed-drill.sh` rehearses a schema reconstructed from local candidate
`3d789538` and runs the current core migration twice against retained data. It is not a published 1.0 artifact,
does not install old package code or dependencies, and does not qualify an upgrade. The baseline drill remains
unavailable until artifact-specific executable fixtures and publication evidence are added.

After publication inventory establishes each required baseline, record it in `tests/Fixtures/Upgrade/baselines.php`
with its `id`, an `https` inventory evidence link and the SHA-256 hashes under `sha256.core` and
`sha256.filament`. Review an artifact-specific `baselines/<id>/drill.sh` that installs the matching old artifacts,
seeds and upgrades a fresh SQLite fixture, and actually performs every check below. Put the matching archives at
`$AZGUARD_UPGRADE_ARTIFACT_DIR/<id>/core.zip` and `filament.zip`. Run with `--all-supported --report=<new-file>`;
the sibling `<new-file>.fixtures` directory must not already exist. The dispatcher verifies hashes and invokes
each reviewed fixture with a clean environment, an isolated SQLite path and array cache. Its `result.json` must
name the exact `baseline` and `target` and report true for `baseline_installed`,
`seeded_roles_grants_fields_morph_audit`, `target_installed`, `migrations_twice`, `versions_match`, `doctor`,
`allow_deny_parity` and `state_reset` in `checks`. A missing check, failed fixture or missing artifact fails the gate;
fixture logs remain beside the report. These checkpoints are assertions made by the reviewed fixture, not independent
proof that it performed its work. Do not register the `mock` dispatcher test as a baseline.

There is no promised reverse migration from 2.0 to 1.x. Roll back by restoring the matching old code, dependency
lock and a consistent database backup together. Any writes since that backup require an explicit recovery plan.
Keep traffic stopped until the restore and mandatory state reset in the
[operations runbook](../advanced/operations.md#manual-database-changes-and-restores) are complete.

## Morph identities

Before changing an Eloquent morph alias or renaming a model whose class name was stored as its type, follow the
[morph identity inventory, migration and rollback runbook](../advanced/operations.md#morph-identity-and-alias-changes).
The application owns the migration and the audit-history policy. Doctor diagnoses the declared models without
enabling Laravel's process-wide morph-map enforcement or changing database rows.
