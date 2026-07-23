---
name: laravel-dusk
bucket: laravel-testing
version: 0.1.0
description: "Laravel Dusk: browser E2E tests, isolated test DB, run from Docker via host ChromeDriver, Page Objects"
risk: write
persona: oss-dev
tags: ["php", "laravel", "dusk", "e2e", "browser-testing", "chromedriver", "docker"]
requires: []
produces_for: []
outputs: []
snippets:
  - phpunit.dusk.xml
  - browser-test.php
  - dusk-makefile.mk
  - page-object.php
adapters: [claude, cursor, fable]
sha256: ""
---

## Rules

- Base class: Dusk tests extend `Tests\DuskTestCase` (extends `Laravel\Dusk\TestCase`), NOT plain `TestCase`. Browser runs a separate app process — `RefreshDatabase` transactions invisible there.
- NEVER `RefreshDatabase` in Dusk. Instead in `setUp()`: `Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true])` on the isolated DB.
- Isolation: separate `phpunit.dusk.xml` with testsuite `tests/Browser` + forced `DB_DATABASE=<app>_test` (`force="true"`); guard checks in `setUp()` that the connection really points at the test DB / test media disk.
- Env: `.env.dusk.local` (test fails with a clear error if file missing); `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync` so sessions/jobs are visible between test process and `artisan serve`.
- Modes: `DUSK_ENV_MODE=test|current` — `test` (default): data mutations only on `<app>_test` + migrate:fresh; `current`: tests hit current env with no reset (read/smoke only).
- Docker: no ChromeDriver in app image — run it on host (`--port=9515 --allowed-ips= --allowed-origins='*'`); container connects via `DUSK_DRIVER_URL=http://host.docker.internal:9515` with `DUSK_START_CHROMEDRIVER=false`.
- Page Objects: repeated pages → classes in `tests/Browser/Pages/` with `url()`, `assert()`, `elements()` (`@element` shortcuts).
- Run: `php artisan dusk [file]` or make target `test-browser FILE=...`.

## Snippet routing

| Situation | File |
|---|---|
| Test config + DB isolation | `snippets/phpunit.dusk.xml` |
| Browser test (waitFor, press, waitUsing, asserts) | `snippets/browser-test.php` |
| Run Dusk from Docker / start host ChromeDriver | `snippets/dusk-makefile.mk` |
| Extract repeated page into Page Object | `snippets/page-object.php` |

## Pitfalls

- `RefreshDatabase` in Dusk "works" locally and silently corrupts data: test transaction invisible to server process. Only `migrate:fresh --seed`.
- Without `force="true"` in phpunit.dusk.xml env section, vars from `.env.dusk.local` override test DB — migrate:fresh hits the working DB.
- Host ChromeDriver must run with `--allowed-ips=` and `--allowed-origins='*'`, else container connections are dropped.
- Frontend assets must be built before tests (`npm run build`) — check manifest, build automatically.
- `assertSee` on dynamic content is flaky; use `waitFor`/`waitUsing` with timeout.

## Quality checklist

- [ ] Test extends DuskTestCase, not TestCase; no RefreshDatabase
- [ ] phpunit.dusk.xml: separate Browser testsuite + DB_DATABASE=`<app>_test` with force
- [ ] DB-isolation guard before migrate:fresh
- [ ] SESSION_DRIVER=file, QUEUE_CONNECTION=sync in dusk env
- [ ] From Docker: DUSK_START_CHROMEDRIVER=false + DUSK_DRIVER_URL on host.docker.internal
- [ ] Repeated pages as Page Objects

## Links

- https://laravel.com/docs/dusk
- https://github.com/php-webdriver/php-webdriver
- Skill `quality/test-strategy` — pyramid strategy and coverage policy
- Skill `laravel-testing/laravel-testing` — Feature/Unit/Pest, test DB isolation, coverage gate (testing base; Dusk has its own isolation without `RefreshDatabase`)
- Skill `laravel-testing/test-isolation-guard` — hard protection against running on dev/prod DB and media disk
- Skill `devops/db-test-preflight` — preflight of test Postgres DB in Docker
- Skill `frontend-vite/vite-module-loader` — asset build Dusk needs before run

<!-- ru-source-sha256: dc5703d143a576bc1ef0adf04391f2f8018bfce4b1e70258bfdeac399a567e1c -->
