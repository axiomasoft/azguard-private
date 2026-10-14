# Audit

The Audit module keeps a journal of every effective change to grants: who changed what, when, and with which event.
It is a plugin: a panel without `AuditPlugin` keeps no journal.

```php
use AzGuard\Audit\AuditPlugin;

->plugins([AuditPlugin::make(retentionDays: 365)])   // 90 days when omitted
```

For each effect of a write (a grant, revoke or change of a role or permission) one row is written in the same
transaction as the grants, with the `event_id` of the event dispatched for that effect. If the transaction rolls back,
so does the row. The panel must store its grants in the database writer; a panel without one fails the doctor check.

## Install

The `audit_log` table has its own migration. It is not loaded automatically, so an application that never uses the
plugin has nothing pending.

Default storage:

```bash
php artisan vendor:publish --tag=azguard-audit-migrations
php artisan migrate
```

A named storage (one configured under `storages` in `config/azguard.php`) takes a generated migration:

```bash
php artisan azguard:storage:migration ledger     # the six core tables
php artisan azguard:audit:migration ledger       # database/migrations/<date>_create_azguard_ledger_audit_log.php
php artisan migrate
```

`azguard:audit:migration` refuses `default` (use the publish tag), an unknown storage, and a storage that already has
its migration (exit code 2). The table is `{prefix}audit_log`; the default prefix is `azg_`. Rolling the Audit
migration back drops only `audit_log`; the core migration drops the six core tables.

::: warning Existing table
The migration creates the table and fails when it exists. Do not publish it for a table you already have.
:::

## Retention and pruning

`retentionDays` is how long a row is kept. Nothing deletes rows by itself: schedule the command.

```bash
php artisan azguard:audit:prune                       # every panel with AuditPlugin
php artisan azguard:audit:prune --panel=admin
php artisan azguard:audit:prune --before=2026-01-01T00:00:00Z
php artisan azguard:audit:prune --force               # required in production
```

`--before` moves the cut-off further back, never past the retention. A panel without `AuditPlugin` is skipped.
Pruning deletes in short batches in transactions of its own. It touches only the `audit_log` rows of the panel, never
grants, revisions or events, and it does not take the authority lock, so it never blocks a grant and a grant never
blocks it. A deadlock retries the batch up to three times; after that the command exits with 1.

```php
$panel->options(AzGuard\Audit\AuditOptions::class)?->retentionDays;   // null without the plugin
```

## Doctor

`azguard:doctor` reports `audit.table` for every panel with `AuditPlugin`:

- an error with the remedy above while the table is missing;
- an error listing the differences when the columns, indexes or host key types of the table do not match the module's
  schema;
- an error when the panel has no database writer, so no journal can be written.

`storage.migrated` checks the six core tables only.

## Your own journal

`AuditPlugin` is built on the public extension point for this: a `ChangeParticipant` that appends to its own tables
through `OwnedRowsWriter`. Write one when you need another format. See [Hooks and plugins](/advanced/hooks-and-plugins). The contracts you implement and the row format you must keep are
supported as described on [Compatibility](/reference/compatibility) and [Machine formats](/reference/formats#audit-journal-rows).
