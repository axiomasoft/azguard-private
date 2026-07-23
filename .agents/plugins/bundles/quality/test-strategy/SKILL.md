---
name: test-strategy
bucket: quality
version: 0.2.0
description: "Test strategy: pyramid (unit/integration/e2e), coverage policy, mock vs real, which features need which test level, test-suite composition (Arch/Feature/Unit) and Pest Arch architecture tests"
risk: draft
persona: quality
tags: [quality, testing, coverage, validation, ci, architecture, pest]
requires: []
produces_for: [code-review, mutation-testing, playwright-e2e]
outputs: ["docs/03_Dev/Test_Strategy.md"]
snippets: ["test-pyramid.md", "pest-arch-test.php", "phpunit-testsuites.xml"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Test Strategy

Apply when: feature/regression count makes ad-hoc testing impractical (post first MVP; or onboarding a new product / taking over a legacy codebase).

## When NOT to apply
- Prototype / PoC discarded after demo
- One-off data migration
- One-person 50-line automation script
- Before first working MVP / one-off scripts / PoC
- Before architecture is fixed (`architecture.md`) — else tests get rewritten

## Step 1. Test Pyramid — proportions

| Level | Share | Tests | Speed | Maintenance cost |
|:---|:---:|:---|:---|:---|
| **Unit** | ~70% | Pure functions, business logic, edge-cases | ms | low |
| **Integration** | ~20% | Layer ↔ layer (repo ↔ DB, service ↔ cache) | hundreds ms | medium |
| **E2E** | ~10% | End-to-end user scenario via UI/API | seconds-minutes | high |

Antipattern "ice-cream cone": many E2E + few unit = slow CI, fragile tests, false alerts. If it happens — record in `tech-debt-audit`.

## Step 2. Coverage Policy

| Code layer | Min coverage |
|:---|:---:|
| Business logic / domain | ≥ 90% |
| Application / use-cases | ≥ 80% |
| Adapters (DB, HTTP, external API) | ≥ 60% |
| UI / presentation | ≥ 40% |
| Generated / boilerplate | 0% (exclude from coverage report) |

Rule: coverage is a lower bound, not a goal. 100% coverage ≠ 100% correctness. Prefer **mutation testing** for critical modules (Stryker, mutmut, infection).

## Step 3. Mock vs Real

| Testing | Dependency | Mock or Real? |
|:---|:---|:---|
| Unit of pure function | — | n/a |
| Unit with injected dependency | internal service | **mock** (or fake/in-memory) |
| Integration: repository | DB | **real** (testcontainers / SQLite in-memory) |
| Integration: external HTTP API | third-party service | **mock** (wiremock / MSW / VCR) |
| Integration: queue | Kafka/RabbitMQ | **real** (testcontainers) |
| E2E: critical happy path | full stack | **real** (or close staging) |
| E2E: payment gateway | Stripe/PayPal | **sandbox** (never real) |

Forbidden: mocking your own code in integration tests — turns them into sub-unit tests.

## Step 4. Which features need which level

| Feature type | Unit | Integration | E2E |
|:---|:---:|:---:|:---:|
| Algorithm / calc (billing, scoring) | ✅ required | — | — |
| CRUD entity through layers | ✅ | ✅ | — |
| Authorization / RBAC | ✅ | ✅ | ✅ (smoke) |
| Payment flow | ✅ | ✅ | ✅ |
| User registration | ✅ | ✅ | ✅ |
| UI form with validation | ✅ (logic) | — | ✅ (smoke) |
| Background job / cron | ✅ | ✅ | — |
| Migration script | — | ✅ (on copy of prod data) | — |

## Step 5. Test Data Strategy

Pick one primary approach:

| Approach | When | Pros | Cons |
|:---|:---|:---|:---|
| **Fixtures (static)** | Simple scenarios | Reproducible, readable | Hard to maintain as it grows |
| **Factories / Builders** | Medium project | Flexible, DRY | Hidden magic, learning curve |
| **Production replicas (anonymized)** | Complex regressions | Realistic | Expensive, GDPR risks |

Antipattern: shared mutable state between tests (shared DB without cleanup) — breaks isolation, makes run order significant.

## Step 6. Test-suite composition (PHPUnit/Pest)

Not one flat `tests/` — split into named `<testsuite>` so fast tests fail first, slow ones isolated to a separate job:

| Suite | What | Speed | Run |
|:---|:---|:---|:---|
| **Arch** | architecture invariants (Pest Arch) | ms, no DB | first, separate |
| **Unit** | pure logic, isolation via mock | ms | before Feature |
| **Feature** | layer↔layer, DB, HTTP | hundreds ms | main |
| **Context** | end-to-end domain scenarios (opt.) | seconds | after Feature |
| **UnitFilament** / UI-suite | panel/widgets, separate bootstrap | hundreds ms | separate |

`--testsuite=Arch` runs first CI step (fast fail on boundary breach before DB); each suite parallelized in its own job; `<exclude>` removes overlaps (e.g. `Unit` excludes `Unit/Filament`). See `snippets/phpunit-testsuites.xml`.

Enable run strictness at `<phpunit>` level: `failOnWarning`, `failOnRisky`, `failOnEmptyTestSuite`, `beStrictAboutOutputDuringTests`, `executionOrder="random"`.

## Step 7. Architecture tests (Pest Arch)

Encode boundaries/invariants as executable rules. Don't replace unit; protect architecture from erosion. Typical invariant set for layered backend:
- `strict_types=1` everywhere in package/app (`->toUseStrictTypes()`).
- No debug helpers in prod: `dd, dump, ray, var_dump, die` (`->not->toBeUsed()`).
- **Actions** final (`->toBeFinal()`); **DTO / ValueObjects / Events** — `readonly`.
- **Contracts** are interfaces (`->toBeInterfaces()`).
- **Actions and Repositories don't depend on HTTP** (`->not->toUse(Request::class)`).

See `snippets/pest-arch-test.php`. Laravel implementation — `laravel-testing/laravel-testing` and `php-packages/laravel-package-testing`; layer boundaries — `php/laravel-structure`.

## Step 8. CI Integration

```yaml
# .github/workflows/test.yml
stages:
  - lint           # ~10 сек, быстрый fail
  - unit           # ~30 сек, параллелится по модулям
  - integration    # ~2 мин, требует docker services
  - e2e            # ~5-10 мин, отдельная job, не блокирует merge
  - coverage       # gate: < min → fail PR
```

Rule: unit + integration **must pass** before merge. E2E can be nightly or post-merge smoke.

## What the agent adds itself
- "Test time budget": % of feature dev time for tests (standard 30-40%, critical modules 50%+)
- Test framework recommendation per language (see relevant `oss-dev/references/oss-*.md`)
- Flaky tests warning: "3 strikes — quarantine" rule, flakiness rate metric
- Test plan template for a large feature (preconditions / scenarios / expected outcomes)

## Output file `Test_Strategy.md` structure

```markdown
# Test Strategy: [ProjectName]

## 1. Пирамида (целевые пропорции + текущее состояние)
## 2. Coverage policy (по слоям + текущий coverage)
## 3. Mock vs Real (таблица решений на конкретные зависимости проекта)
## 4. Test Data Strategy (выбранный подход + обоснование)
## 5. CI Pipeline (stages + gates)
## 6. Flaky tests policy (как ловим и убираем)
## 7. Test budget (% времени на тесты)
## 8. Open questions / TODO
```

## Hard prohibitions

FORBIDDEN:
- Writing E2E without unit coverage of the same logic
- Coverage gate < 60% for domain layer (marker "tests aren't written at all")
- Shared mutable DB between tests without cleanup
- Mocking the standard library (datetime, fs) instead of injecting an abstraction — makes tests fragile to refactoring
- Treating coverage as a goal instead of a risk metric
- Running tests against production environment

## Links
- Strategy implementations: `laravel-testing/laravel-testing` (app), `php-packages/laravel-package-testing` (packages), `laravel-testing/laravel-dusk` (E2E), `frontend-vue/vitest` (frontend), `quality/playwright-e2e` (native TS E2E)
- `quality/mutation-testing` — measure test quality (MSI) on top of coverage policy
- `quality/code-review` — "are there tests on new logic" criterion relies on this strategy
- `quality/tech-debt-audit` — where ice-cream cone and coverage debt are logged
- `laravel-testing/test-isolation-guard`, `devops/db-test-preflight` — test DB isolation and preflight
- `devops/ci-cd` — coverage gate as separate CI job (threshold from this coverage policy), running suites per job
- `php/laravel-structure` — layer boundaries checked by Arch invariants
- snippets/pest-arch-test.php — Pest Arch rules set (strict types, no-debug, final actions, readonly DTO/VO/Events, contracts=interfaces, no Request in Actions/Repositories)
- snippets/phpunit-testsuites.xml — `<testsuites>` Arch/Feature/Context/Unit + strict run flags

<!-- ru-source-sha256: 55764298eae5df06136709b1a3a23b456b312712a1ff9ef209ab00366b21a8bc -->
