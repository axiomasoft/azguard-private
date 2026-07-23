---
name: test-isolation-guard
bucket: laravel-testing
version: 0.1.0
description: "Guard Laravel tests against running on prod/dev DB and prod media disk: bootstrap.php sets DB_DATABASE=*_test and media-test before autoload, TestEnvironmentGuard aborts createApplication() on isolation breach, safe RefreshDatabase. Activate when setting up the test environment, protecting CI, or suspecting tests hit dev/prod DB."
risk: write
persona: oss-dev
tags: [php, laravel, testing, isolation, safety, database, ci, refreshdatabase]
requires: [laravel-testing]
produces_for: []
outputs: []
snippets: [bootstrap.php, TestEnvironmentGuard.php, TestCase.php]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: Test isolation guard

Triggers: setting up test env for new project/package; protecting CI (runner must fail with clear message, not silently wipe data); suspecting tests hit dev/prod DB. Three defense lines: bootstrap before autoload, guard on `createApplication()`, safe `RefreshDatabase`. Version-agnostic testing basics (Feature/Unit/Pest, Docker/local detect) live in `laravel-testing/laravel-testing` (`requires`).

## Algorithm

1. **Define test resources:** test DB name by convention `<app>_test` (MUST end with `_test`); test media disk `media-test` with isolated local `root`; ensure test DB and disk exist (or document how to create them).

2. **Line 1 — `phpunit.xml`.** In `<php>` set: `APP_ENV=testing`, `DB_CONNECTION`, `DB_DATABASE=<app>_test`, `MEDIA_DISK=media-test`, plus `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.

3. **Line 1.5 — `tests/bootstrap.php`** (see `snippets/bootstrap.php`). Wire via `phpunit.xml` attr `bootstrap="tests/bootstrap.php"`. Sets same `DB_DATABASE=*_test` and `MEDIA_DISK=media-test` **before** `require vendor/autoload.php`, writing to `$_SERVER`, `$_ENV` AND via `putenv()`. `env()` source priority: `$_SERVER` > `$_ENV`; `putenv()` covers `getenv()` — write all three.

4. **Line 2 — guard class `Tests\Support\TestEnvironmentGuard`** (see `snippets/TestEnvironmentGuard.php`). Read via `config` container (not raw `env`):
   - `assertIsolatedTestDatabase()`: take `database.default`, then `database.connections.{default}.database`, check `str_ends_with($database, '_test')`. Else throw `RuntimeException` with clear Russian message + instructions (what to check in `phpunit.xml`/`bootstrap.php`, how to create test DB).
   - `assertIsolatedTestMediaDisk()`: take `media-library.disk_name`, check it is `media-test` and `filesystems.disks.media-test` is described as array. Else `RuntimeException` with instructions.
   - Messages MUST be self-sufficient: fixable from CI log without reading sources.

5. **Line 2 — wire guard in `Tests\TestCase`** (see `snippets/TestCase.php`). Override `createApplication()`: call `parent::createApplication()`, then both `assert*` guards, only then return `$app`. Fires on EVERY app boot — before first DB query and before file writes; on breach test fails instantly, `migrate:fresh` never runs.

6. **Line 3 — safe `RefreshDatabase`.** Only after lines 1–2 in place, add trait `Illuminate\Foundation\Testing\RefreshDatabase` to DB tests (or `pest()->extend(...)->use(RefreshDatabase::class)->in('Feature')`).

7. **Forbid commands outside test env.** Fix in project/agent rules: without explicit `APP_ENV=testing` AND `DB_DATABASE=*_test`, do NOT run `migrate:fresh`, `db:wipe`, `db:seed`, `migrate`, nor `tinker` with mutations.

8. **Verify the guard fires.** Temporarily point env at a non-test DB (or run with empty `phpunit.xml`), confirm run fails with clear message before `migrate:fresh`. Restore correct env.

## Isolation rules

- **`config`, not `env`** — guard reads final resolved config (what framework uses); `env()` may differ due to config cache + source priority.
- **`str_ends_with(..., '_test')`, not exact name** — portable; only `_test` suffix required.
- **Three env sources** — `bootstrap.php` writes `$_SERVER`, `$_ENV`, `putenv()` simultaneously.
- **Guard is last line, not the only one** — does not replace `phpunit.xml`/`bootstrap.php`, catches their regression; do not drop first lines.
- **Media disk = separate check** — DB and files isolated independently; test disk has own `root` non-overlapping with prod.
- **Messages in Russian + instructions** — each exception explains what broke and how to fix (where to look, how to create resource).

## Quality checklist

- [ ] `phpunit.xml` sets `APP_ENV=testing`, `DB_DATABASE=*_test`, `MEDIA_DISK=media-test` and wires `tests/bootstrap.php`
- [ ] `tests/bootstrap.php` sets test `DB_DATABASE`/`MEDIA_DISK` in `$_SERVER`, `$_ENV`, `putenv()` BEFORE `require vendor/autoload.php`
- [ ] `TestEnvironmentGuard::assertIsolatedTestDatabase()` checks `_test` suffix via `config`, not `env`
- [ ] `TestEnvironmentGuard::assertIsolatedTestMediaDisk()` checks `media-test` disk and presence of its config
- [ ] Both exceptions are `RuntimeException` with Russian message + fix instructions
- [ ] `TestCase::createApplication()` calls both guards before returning `$app`
- [ ] `RefreshDatabase` added only after lines 1–2; run on non-test DB impossible
- [ ] Guard fire verified manually: non-test DB aborts before `migrate:fresh`
- [ ] Ban on `migrate:fresh`/`db:wipe`/`db:seed`/`tinker`-mutations outside `APP_ENV=testing` recorded

## References

- snippets/bootstrap.php — `tests/bootstrap.php` with isolation before autoload
- snippets/TestEnvironmentGuard.php — DB + media disk guard class
- snippets/TestCase.php — guard in `createApplication()` + safe `RefreshDatabase`
- https://laravel.com/docs/database-testing#resetting-the-database-after-each-test
- https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Testing/RefreshDatabase.php
- Related skills: `laravel-testing/laravel-testing` (base, requires), `laravel-extras/medialibrary` (test media disk), `laravel-testing/laravel-dusk` (browser-test isolation — separate scheme without `RefreshDatabase`), `devops/db-test-preflight` (container/DB preflight before run)

<!-- ru-source-sha256: 62fa8640b4775e87da13d24fd0bdd64c21ead4e670dabf14ee1d369d80f49308 -->
