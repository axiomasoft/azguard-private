# CRM acceptance suite

An end-to-end Testbench/Pest stand on the real Authorizer, panel providers, Eloquent and storage over SQLite
`:memory:` (PostgreSQL and MySQL through `DB_CONNECTION`). The clock is fixed at 2026-10-06 12:00 UTC.

- **World.** Panels `crm` and `backoffice`; organizations A and B (tenants); projects P1–P5 (assignment scopes);
  clients C1–C6 with a composite foreign key project + tenant; people Anna, Boris, Daria and an outsider.
- **Setup.** Assignments are written through `Storage::mutate`; changes and runtime permissions go through the
  change pipeline.
- **Expectations.** Literal ids and decision reasons, each with a positive control.

Run: `DB_CONNECTION=sqlite php -d memory_limit=1G vendor/bin/pest tests/Acceptance/Crm`. The suite is part of
`composer test`.

## Not covered by this stand

- Two external providers with the same local external id keeping separate host references, grants and queries.
- Mixed real sources with a plugin and an LDAP-style consumer.
- A complete external sync protocol (webhooks, timeouts).
- A real Octane worker; the request lifecycle is covered with `forgetScopedInstances`.
- The framework matrix with an installed external consumer.

## Scale facts

`ScaleVisibilityTest` filters 10 000 clients over 100 scopes and two role shapes: 8 000 allowed rows, selection
chunks of 500, at most 5 000 bindings per statement. `tests/Engines/VisibilityExplainTest.php` records real `EXPLAIN` output for 100 000 rows
on PostgreSQL and MySQL and asserts the indexes actually chosen, without planner hints.
