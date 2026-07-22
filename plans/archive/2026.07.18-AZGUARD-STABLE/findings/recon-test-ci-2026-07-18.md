# Recon: тест/CI-инфраструктура azguard (2026-07-18)

> Слой 1. Отчёт Explore-субагента (read-only разведка репо). Вердикт: repo-grounded.

## 1. Тесты

Структура `tests/` (монорепо, единый набор на корне, покрывает все 3 пакета):

- `tests/Unit/` — 4 файла: BladeDirectiveTest, OctaneScopingTest, PermissionKeyTest, ApiBoundaryTest
- `tests/Feature/` — 55 файлов (авторизация, гранты, CLI, кэш, панели, скоуп-роли)
- `tests/Feature/Context/` — 8 файлов (мультиворкспейс-контекст)
- `tests/Feature/Filament/` — 5 файлов (enforcement ресурсов/страниц/виджетов, eager-load, memoization)
- `tests/ArchTest.php` — ~38 arch-выражений, отдельный testsuite `Arch`
- Базовые кейсы: TestCase, FilamentTestCase, ContextTestCase, MorphTypeTestCase, ManagerSwapTestCase + tests/Pest.php
- Стабы `tests/Stubs/`, миграции `tests/database/migrations/`

**Число тестов:** ~501 кейс (489 `it()` + 12 `test()`); 107 файлов `*Test.php`.

**Инструменты:** Pest ^4.7 + type-coverage plugin ^4.0; testbench ^9|^10|^11; phpstan ^2 +
larastan ^3; infection ^0.34; pint ^1.13; rector ^2.0. **paratest НЕ подключён** (только
транзитивный suggest) — параллельного прогона нет.

**Testsuites** (phpunit.xml): Arch, Feature (без Context), Context, Unit (без Unit/Filament),
UnitFilament. `executionOrder="random"`, failOnWarning/Risky, strict output. БД — sqlite
`:memory:`, cache array.

**Дыры:**
- `packages/filament/src/Resources` исключён из coverage-source (декларативный UI);
- testsuite `UnitFilament` объявлен, но каталога `tests/Unit/Filament` НЕТ (мёртвый suite);
- `tests/Pest.php` ссылается на несуществующий `Feature/DiscoveryTest.php` (устаревшая ссылка).

## 2. CI (.github/workflows/, 13 файлов)

- **tests.yml** — матрица PHP 8.3/8.4/8.5 × Laravel 11/12/13 × prefer-lowest/stable
  (testbench L11→^9, L12→^10, L13→^11; exclude 8.5+L11/L12); composer validate --strict;
  pest --ci на sqlite. Отдельный джоб coverage (PHP 8.4, xdebug): --min=50 + Codecov
  (continue-on-error).
- **static-analysis.yml** — larastan level 6 + type coverage --min=98.
- **code-style.yml** — Pint --test + Rector --dry-run.
- **release.yml** — триггер по тегу `v*`; validate (манифесты, сателлиты не пиннят core
  через `*`, analyse, lint, тесты) → release (git-cliff → gh-release, prerelease при `-`).
- **split.yml** — по тегу сплит монорепо в 3 read-only репо (symplify monorepo-split),
  Packagist по вебхуку.
- **changelog.yml** (git-cliff по тегу, коммит обратно), **release-drafter.yml**.
- **docs.yml** — parity-гейт EN/RU + docs-php-version gate; VitePress build; GitHub Pages.
- codeql.yml (еженедельный cron), zizmor.yml (аудит workflow), pr-check.yml (Conventional
  title + size labeler), dependabot-auto-merge.yml.

## 3. Mutation testing

infection.{core,filament,context}.json5; мутаторы @default; логи build/infection/.

| Пакет | minMsi | minCoveredMsi | excludes |
|---|---|---|---|
| core | 70 | 80 | Commands, Facades |
| filament | 60 | 75 | Commands, Resources, Pages |
| context | 65 | 80 | Commands |

CI mutation.yml: на PR — diff-scoped (`--git-diff-lines --git-diff-base=origin/main`),
блокирующий; на push main/manual — полный per-package, advisory (continue-on-error).
Coverage-XML генерится один раз, переиспользуется.

## 4. Docker

**Docker/compose в репо НЕТ** (найденное — только шаблоны скиллов в .claude/skills/).
Тесты локально и в CI — sqlite :memory:, без реальных БД. Параллелизм — только матрицей
CI-джобов; infection --threads=4.

## 5. Качество-гейты (composer scripts)

- test / test:coverage(--min=50) / test:unit / test:feature / test:types(--min=98)
- lint(:check) Pint · refactor(:check) Rector · analyse (phpstan --memory-limit=1G)
- check:coverage → bin/coverage-gate.sh; mutate:{core,filament,context,all,diff} →
  bin/mutation-gate.sh
- docs:parity, docs:php
- Агрегат **check**: lint:check → analyse → refactor:check → test:types → test →
  check:coverage → mutate:all
- PHPStan level 6, baseline **35 записей** (~8.4 KB, «должен уменьшаться»); один точечный
  ignore на RolePermissionsRelationManager.php.
- Pint preset laravel + 3 правила; Rector PHP 8.3 sets + CQ/DEAD_CODE/EARLY_RETURN/TYPE_DECL,
  skip BaseRole.php.
- Honest-skip: coverage/mutation-gate.sh при отсутствии pcov/xdebug локально выходят 0
  с громким warning (в CI драйвер всегда есть).

## 6. Якоря

composer.json · phpunit.xml · tests/Pest.php · tests/ArchTest.php · phpstan.neon +
phpstan-baseline.neon · rector.php · pint.json · infection.*.json5 ·
bin/{coverage-gate,mutation-gate,docs-parity-gate,docs-php-version-gate}.sh ·
.github/workflows/{tests,mutation,static-analysis,code-style,release,split,changelog,docs,pr-check,codeql,zizmor}.yml ·
корневые доки: ARCHITECT_REVIEW.md, IMPROVEMENT_PLAN.md, REMAINDER_REPORT.md,
DEVELOPMENT.md, RELEASING.md
