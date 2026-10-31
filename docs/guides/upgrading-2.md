# Upgrading to 2.0

## Morph identities

Before changing an Eloquent morph alias or renaming a model whose class name was stored as its type, follow the
[morph identity inventory, migration and rollback runbook](/advanced/operations#morph-identity-and-alias-changes).
The application owns the migration and the audit-history policy. Doctor diagnoses the declared models without
enabling Laravel's process-wide morph-map enforcement or changing database rows.
