# Known limitations

These are current support boundaries for the 1.0 candidate. See the
[upgrade guide](./upgrading.md) for migration steps.

- **Database engines:** the declared exact scope identity profile is PostgreSQL 16+,
  SQLite 3.35+, and non-MariaDB MySQL 8.0.13+ with InnoDB 16 KiB pages and
  `DYNAMIC` or `COMPRESSED` row format. MariaDB and smaller MySQL page profiles
  are unsupported without their own live DDL proof. Migration `000006` rejects
  unsupported profiles before schema-changing DDL.
- **API snapshot:** `ApiBoundaryTest` freezes the tagged core API. Filament and
  context do not yet have matching reflection snapshots; review their public
  surfaces when changing them.
- **Legacy wildcard grammar:** `features.wildcard_permission=true` remains a
  deprecated one-cycle compatibility option. New integrations should use the
  default segment-aware matcher.
- **Headless setup:** a zero-panel, permissive runtime is not supported. Follow
  the documented minimal panel setup; strict panel validation stays fail-closed.
- **Test fakes:** a blanket `Event::fake()` suppresses the listeners used by
  `AzGuard::fake()` to record grant events. Keep those events real when asserting
  recorded grants.
