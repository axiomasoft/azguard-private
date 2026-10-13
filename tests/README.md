# Tests

Pest 4 on Orchestra Testbench. Every test directory belongs to a **zone** (`tests/zones.json`, the one source for the
groups, `composer test:changed` and `tests/Arch/ZonesMapTest.php`, which fails when a source directory or a test file is
in no zone).

## Zones

| Zone | Source (`packages/…`) | Tests | Uses |
|---|---|---|---|
| `kernel` | core `Kernel`, `Attributes`, `Exceptions`, `Support`, `Contracts`, `Context`, `Tenancy` | `Unit/Kernel`, `Unit/Exceptions`, `Unit/Contracts`, `Feature/Support` | — |
| `catalog` | core `Catalog`, `Panels`, `Roles`, `Permissions`, `Configuration`, `Plugins`, `Policies`, `Schema`, `Events` | `Unit/{Catalog,Panels,Roles,…}`, `Feature/{Catalog,Panels,…}` | kernel |
| `storage` | core `Storage` | `Unit/Storage`, `Feature/Storage` | kernel |
| `sources` | core `Sources` | `Unit/Sources`, `Feature/Sources`, `Contracts/Sources`, `Contracts/Resolvers` | kernel, catalog, storage |
| `changes` | core `Changes` | `Unit/Changes`, `Feature/Changes`, `Contracts/Hooks` | kernel, catalog, storage, sources |
| `authorization` | core `Authorization`, `Concerns`, `Facades` | `Unit/{Authorization,Facades}`, `Feature/{Authorization,Gate,Http,…}`, `Contracts/Restrictions` | kernel, catalog, storage, sources |
| `visibility` | core `Directories` | `Unit/Directories`, `Feature/{Visibility,Directories,Parity}` | kernel, authorization, storage, sources |
| `scopes` | module `scopes` | `Modules/Scopes` | kernel, catalog, storage, sources, authorization |
| `audit` | module `audit` | `Modules/Audit` | kernel, catalog, storage, sources, changes |
| `devtools` | module `devtools` | `Modules/DevTools` | kernel, catalog, storage, sources, changes, testing-kit |
| `testing-kit` | core `Testing` | `Unit/Testing`, `Feature/Testing`, `Contracts/Plugins`, `Contracts/NegativeFixturesTest.php` | kernel, storage, sources, changes, authorization |
| `console` | core `Laravel`, `Diagnostics`, the service provider | `Feature/{Console,Diagnostics,Laravel}`, `Feature/ServiceProviderTest.php` | all of core |
| `filament` | `filament/src` | `Feature/Filament` | core |
| `bench` | `bench` | `Feature/Bench` | kernel, authorization, storage |
| `engines` | — | `Engines` (races, replication, constraints on a real server) | storage, sources, changes, visibility |
| `acceptance`, `regression` | — | `Acceptance/Crm`, `Regression` (tail zones: they use everything) | core, filament |
| `arch` | — | `Arch` (always selected, a tail zone) | — |

## Ownership of module tests

A module's tests live in `tests/Modules/<Module>` and travel with it: `Feature` (behavior), `Unit`, `Contracts` (the module's
own contract suites and their negative controls), `Properties`, `Fixtures` (namespace `AzGuard\Tests\Modules\<Module>\Fixtures`).
`tests/Arch/TestOwnershipTest.php` pins the rule: a file under `tests/Modules/<Module>` references no other module, and a
file outside `tests/Modules` references none either unless `tests/Arch/Fixtures/module-consumers.json` lists it (core
tests, world fixtures and snapshots that use a module as a consumer does; the list is exact and only shrinks). The algebra
of scope properties stays in core (`Feature/Authorization/Properties/ScopePropertiesTest.php`, with a no-declaration
variant); the scope-mode tables are in `Modules/Scopes/Properties`.

| Module | Tests |
|---|---|
| Scopes | `Modules/Scopes/{Feature,Unit,Contracts,Properties,Fixtures}`; `Contracts/FilterContractTest.php` runs the supported `AzGuard\Scopes\Testing\AssignmentScopeFilterContractTests` on the module's own filters |
| Audit | `Modules/Audit/{Feature,Contracts}`: plugin, prune command, plugin and journal-participant contracts (concurrency stays in `Engines`) |
| DevTools | `Modules/DevTools/Feature`: `InstallCommandTest`, `Generators/*` |

## Groups

| Group | What it selects |
|---|---|
| `zone:<name>` | The tests of a zone. |
| `database` | The directories whose result depends on the database engine, listed in `zones.json`: `Feature/Storage`, `Feature/Sources/Database`, `Feature/Changes`, `Feature/Visibility`, `Feature/Directories`, `Feature/Parity`, `Contracts`, `Modules/Scopes/Contracts`, `Modules/Audit/Contracts`, `Engines`. Only these run on PostgreSQL, MySQL and MariaDB. |
| `database:<zone>` | The part of a zone that is also in `database`. |
| `extended` | The scale tests (R58): minutes at 10000 resources, the same budgets run at 1000 on every run. Nightly CI and a release run them. |
| `engines`, `redis`, `replica` | Tests that need their own services (the engine suite, Redis, a streaming replica). |
| `stand`, `server-skip` | Umbrella groups of what a parallel run leaves out (SQLite: `stand`; a server driver: `server-skip`). ParaTest honours only the first `--exclude-group`. |
| `sqlite` | Checks that differ by driver and run on SQLite only. |

## Commands

| Command | Runs |
|---|---|
| `composer test` | The SQLite lane: everything except `engines`, `redis`, `replica`, `extended`. |
| `composer test:parallel` | The same on 4 workers. |
| `composer test:changed` | The zones of the changed files and the zones that use them (`--quick` drops `acceptance`, `regression` and `arch`; `--base=main`; `--db` adds the database lane on `DB_CONNECTION`; `--dry-run` prints the command). A change to `Pest.php`, `TestCase`, the fixtures or the composer files selects nothing and says to run the full suite. |
| `composer test:db -- <driver>` | The `database` group on `pgsql`, `mysql` or `mariadb`; `--parallel[=N]` one database per worker, `--zone=<zone>`, `--full` all parity seeds (the default is `PARITY_SEEDS=1,2`), `--engines` the engine suite. |
| `composer test:extended` | The `extended` group. |
| `composer test:redis`, `composer test:replica` | The Redis and replica lanes. |

Local work needs the SQLite lane only (`composer test:changed`); a server driver matters when the change is in
`storage`, `sources`, `changes` or `visibility`. CI runs every lane; nightly CI adds `extended` and every parity seed.

## Writing a test

- Put it in the directory of its zone; a new directory goes into `zones.json` first (the arch test fails otherwise).
- A test that needs no schema should not create one: on a server DDL is most of a test's cost.
- A dataset that multiplies a dimension no code reads (`local`/`production` for a guard that ignores the environment)
  is a loop in one test, or no dimension at all. Give the reason in the test.
- A result that depends on the engine (SQL, constraints, transactions, collation, JSON) belongs in a `database`
  directory, so every server runs it. A pure-logic test there only costs time.
- The scale of a test is a budget, not the point: assert the budget at a size that runs on every PR and put the
  bigger size in `extended`.
