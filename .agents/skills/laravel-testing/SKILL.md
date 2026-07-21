---
name: laravel-testing
bucket: laravel-testing
version: 0.4.1
description: "Feature/unit/pest testing patterns: test DB isolation, Docker/local env detection, coverage gate, Pest composition (setUp traits, fixtures, beforeEach fake/freeze, datasets)"
risk: write
persona: oss-dev
tags: ["php", "laravel", "testing"]
requires: []
produces_for: []
outputs: []
snippets: ["feature-test.php", "unit-test.php", "factory-pattern.php", "pest-test.php", "pest-composition.php", "pest-testing.md", "testing-rules.md", "testing-safety-report.md", "phpunit.xml", "coverage-gate-config.php", "check-coverage-gate.php", "composer-test-scripts.json"]
adapters: [claude, cursor, fable]
sha256: ""
---

## Scope

Laravel testing (Pest/PHPUnit): Feature/Unit patterns, test-DB isolation from working DB, env detection (Docker vs local PHP), CI coverage gate. Defaults:
- Feature by default; Unit only for pure logic without DB.
- Assert behavior (HTTP code, DB state, notifications), not internal implementation.
- Data via model factories.

Activate when: writing/editing Laravel tests (`tests/Feature`, `tests/Unit`, `*Test.php`, Pest `it()/test()`); configuring `phpunit.xml`, test-DB isolation, model factories; building CI coverage gate; running `php artisan test` / `vendor/bin/pest` needing env detection; reusing setUp via traits/`beforeEach`/datasets. Do NOT activate for app architecture (skill `laravel`) or pyramid strategy (skill `quality/test-strategy`).

Laravel Boost: Pest syntax/idioms = Boost skill `pest-testing`; here = test-DB isolation, coverage gate, env. Package: https://github.com/laravel/boost (skills in `vendor/laravel/boost/.ai/`).

## Algorithm

### 1. Test DB isolation (dual-DB scheme)
Two DBs: main `<app>` (`.env` `DB_DATABASE`) and test `<app>_test`. Tests hit ONLY `<app>_test`.
- `phpunit.xml` sets `DB_DATABASE=<app>_test` and `APP_ENV=testing` TWICE: via `<server>` (Laravel/Dotenv reads `$_SERVER` first — ServerConstAdapter) AND via `<env force="true">` (`force` overrides vars Docker/host inject via `getenv`). Without `force="true"` tests may hit main DB.
- DB host/port/user/password inherited from env; only DB name is swapped.
- Light drivers: `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, separate disk `MEDIA_DISK=media-test` (all with `force="true"` where env may override).
- Template: snippet `phpunit.xml`.

### 2. Env detection: Docker vs local PHP
Do NOT pick mode by `DB_CONNECTION` (it is `pgsql` in both). Use `DB_HOST`:

| `DB_HOST` | Mode | Commands |
|:---|:---|:---|
| `pgsql` (compose service name) | Docker | `docker compose exec app php artisan test`, `docker compose exec app composer ...` |
| `127.0.0.1` / `localhost` / IP | Local | `php artisan test`, `vendor/bin/pest` |

If unsure: `docker compose ps` — if containers up, use Docker. Details/command table: snippet `testing-rules.md`.

### 3. Forbid destructive commands on main DB
- NEVER run `migrate:fresh` / `db:wipe` without explicit test env (`--env=testing` / `APP_ENV=testing`): `RefreshDatabase` and these commands wipe the DB the current env points at.
- Guard in `TestCase` — before run, assert connected to `<app>_test`:

```php
// tests/TestCase.php
protected function assertIsolatedTestDatabase(): void
{
    $database = DB::connection()->getDatabaseName();

    if (! str_ends_with($database, '_test')) {
        throw new RuntimeException("Тесты подключены к '{$database}', ожидалась '<app>_test'. Прогон остановлен.");
    }
}
```

- Danger zones outside PHPUnit/Pest (guard does NOT catch — app boots from normal `.env`): `php artisan tinker` (factories/`create()` mutate main DB), `db:seed`, any ad-hoc scripts — only with `APP_ENV=testing`. Full breakdown: snippets `testing-rules.md`, `testing-safety-report.md`.

### 4. Coverage gate
- Config `coverage.php` at root: baseline 70% global line minimum, 55% for critical paths (`app/Actions`, `app/Policies`, `app/Services`, `app/Http/Middleware`); overridden by env vars `COVERAGE_GLOBAL_MIN` / `COVERAGE_CRITICAL_MIN` — snippet `coverage-gate-config.php`.
- Gate script reads `coverage/clover.xml` after `php artisan test --coverage --coverage-clover=...`; modes `COVERAGE_GATE_MODE=report|soft|hard` — snippet `check-coverage-gate.php`.
- Composer pipeline: `test` (config:clear → lint:check → artisan test), `test:coverage`, `test:coverage:clover`, `test:coverage:gate`, `test:critical` (named list of critical suites) — snippet `composer-test-scripts.json`.

## Pest composition: traits + fixtures
Boost owns base Pest syntax (test, `expect`, `pest()` nav). Here = delta: reuse setUp logic and keep suite deterministic via COMPOSITION, not inheritance. Do NOT spawn `TestCase` subclasses (`AdminTestCase`, `ApiTestCase`, `OrderTestCase`). Compose behavior from small traits + helpers.

### 1. Reusable setUp traits (concerns), not subclasses
Each repeated test prefix = one trait in `tests/Support/Concerns/` with a narrow responsibility:
- `ActsAsUser` — `makeUserWithRole($role)` / `actingAsRole($role)`: create user via factory, assign role, authorize.
- `SeedsUserRoles` (or `SeedsLookupData`) — `seedUserRoles()`: load narrow lookup (roles/statuses/types) that access asserts depend on; does NOT duplicate `DatabaseSeeder`.

`TestCase` stays thin and USES traits (no class-chain inheritance):

```php
// tests/TestCase.php
abstract class TestCase extends BaseTestCase
{
    use ActsAsUser;       // хелперы доступа
    use SeedsUserRoles;   // справочник ролей
    // + guard изоляции тестовой БД (см. секцию выше)
}
```

Trait helpers are available in ALL Pest closures automatically (Pest binds closure to `TestCase`, so `$this->actingAsRole(...)` works inside `it(...)`). New need = new trait, not new subclass. For behavior needed by only some suites, bind the trait in `Pest.php` precisely: `pest()->extend(TestCase::class)->use(SeedsLookupData::class)->in('Feature/Admin')`.

### 2. Single env hygiene via `beforeEach` in `tests/Pest.php`
Set net/time determinism ONCE per directory, not per file. Bind via `->in('Feature')` / `->in('Unit')`:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)   // только Feature
    ->beforeEach(function () {
        Str::createRandomStringsNormally();   // сброс возможного фейка из прошлого теста
        Str::createUuidsNormally();
        Http::fake(['127.0.0.1:5173/*' => Http::response('')]);   // гасим dev-ассеты
        Http::preventStrayRequests();         // любой неподделанный HTTP = падение
        Sleep::fake();                        // sleep() в коде не тормозит прогон
        $this->freezeTime();                  // Carbon::now() заморожен
    })
    ->in('Feature');
```

Three pillars: `Http::preventStrayRequests()` turns silent external call into explicit failure; `Sleep::fake()` removes real pauses; `freezeTime()` stabilizes time asserts. For `Unit` — same block but WITHOUT `RefreshDatabase` (pure logic, no DB). Custom `expect()->extend(...)` and global helpers also live in `Pest.php`.

### 3. Datasets `->with()` — parametrize, not copy-paste bodies
One scenario × many inputs = dataset, not N near-identical tests. Named `dataset()` (string keys) makes failure output readable (`with data set "orders.cancel"`):

```php
dataset('order_guest_endpoints', [
    'cancel'  => ['orders.cancel', ['reason' => 'duplicate']],
    'confirm' => ['orders.confirm', []],
]);

it('закрывает endpoint от гостя', function (string $route, array $payload) {
    $order = Order::factory()->pending()->create();   // фабрика Eloquent + states
    $this->post(route($route, $order), $payload)->assertRedirect(route('login'));
})->with('order_guest_endpoints');
```

Local set = inline `->with([...])` in the test; shared/cross-file = named `dataset()`. Data ALWAYS via model factories (`Model::factory()->state()->create()`) and test factory-helpers (`tests/Support/Factories/`) building a coherent graph (`Order` + participants + items) — never manual `INSERT`, never hardcoded ids.

### Boundary with Boost

| Topic | Where |
|:---|:---|
| Base Pest: `it/test`, `expect`, `pest()`, test nav | Boost skill `pest-testing` |
| Test-DB isolation, coverage gate, Docker/local detect | this skill, sections above |
| Composition: setUp traits, fixtures, `beforeEach` fake/freeze, datasets | this skill, this section |

Full example (two concern traits, `Pest.php` with `beforeEach` for Feature and Unit, custom expectation, named + inline datasets) — snippet `pest-composition.php`.

## Which snippet to open

| Situation | File |
|:---|:---|
| Feature test (HTTP, API, access) | `feature-test.php` |
| Unit test, pure logic, no DB | `unit-test.php` |
| Model factory / states | `factory-pattern.php` |
| Test in Pest syntax | `pest-test.php` |
| Reuse setUp traits/fixtures, `beforeEach` fake/freeze, `->with()` datasets | `pest-composition.php` |
| Agent workflow with Pest, Feature/Unit choice, debugging failures | `pest-testing.md` |
| Before running tests: Docker vs local, command table, prohibitions | `testing-rules.md` |
| How DB isolation works, creating `<app>_test` | `testing-safety-report.md` |
| phpunit.xml with isolated test DB | `phpunit.xml` |
| Project coverage thresholds | `coverage-gate-config.php` |
| CI gate over clover.xml | `check-coverage-gate.php` |
| composer test/coverage scripts | `composer-test-scripts.json` |

## Quality checklist
- [ ] `phpunit.xml`: `DB_DATABASE=<app>_test` and `APP_ENV=testing` via `<server>` + `<env force="true">`
- [ ] cache/session = array, queue = sync, media = separate test disk
- [ ] `TestCase` has guard assert on test-DB name
- [ ] No `migrate:fresh`/`db:wipe`/`db:seed`/`tinker` against main DB
- [ ] Mode (Docker/local) determined by `DB_HOST`, commands run from that same env
- [ ] Feature by default; Unit = pure logic; data via factories; asserts behavioral
- [ ] Coverage gate in CI: global ≥ 70%, critical paths ≥ 55%
- [ ] Repeated setUp extracted to trait `tests/Support/Concerns/`, not a new `TestCase` subclass (composition, not inheritance)
- [ ] `Pest.php`: `beforeEach` with `Http::preventStrayRequests()` + `Sleep::fake()` + `freezeTime()`; `RefreshDatabase` only in Feature
- [ ] Multiple inputs via `->with()`/`dataset()`, not copy-pasted bodies; data via model factories

## References
- Skill `quality/test-strategy` — pyramid strategy and coverage policy
- Skill `static-analysis` (bucket php) — pipeline rector → pint → phpstan → tests
- Skill `laravel` (bucket php) — architectural patterns covered by tests
- Skill `laravel-testing/test-isolation-guard` — hard isolation (bootstrap + `createApplication` guard), layered on top of this skill
- Skill `laravel-testing/laravel-dusk` — browser E2E tests (own isolation, no `RefreshDatabase`)
- Skill `php/pao` — compact JSON PHPUnit/Pest output for AI agent
- Skill `devops/db-test-preflight` — test Postgres DB pre-flight before run
- https://pestphp.com/docs — Pest documentation

<!-- ru-source-sha256: d67c0894907f8665d97e5de1bd0a6c8f69ce4c22b6231e650cb6ef2b2cc6f929 -->
