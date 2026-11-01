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

There is no promised reverse migration from 2.0 to 1.x. Roll back by restoring the matching old code, dependency
lock and a consistent database backup together. Any writes since that backup require an explicit recovery plan.
Keep traffic stopped until the restore and mandatory state reset in the
[operations runbook](../advanced/operations.md#manual-database-changes-and-restores) are complete.

## Morph identities

Before changing an Eloquent morph alias or renaming a model whose class name was stored as its type, follow the
[morph identity inventory, migration and rollback runbook](../advanced/operations.md#morph-identity-and-alias-changes).
The application owns the migration and the audit-history policy. Doctor diagnoses the declared models without
enabling Laravel's process-wide morph-map enforcement or changing database rows.
