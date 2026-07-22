---
name: static-analysis
bucket: php
version: 0.2.0
description: "PHP/Laravel static analysis & linting: Laravel Pint, PHPStan, Rector locally + CI, reference configs"
risk: write
persona: oss-dev
tags: [php, laravel, quality]
requires: []
produces_for: []
outputs: []
snippets: ["pint.json", "phpstan.neon", "rector.php", "qa-composer-scripts.json"]
adapters: [claude, cursor, fable]
sha256: ""
---

Three tools, used together locally + in CI: **Laravel Pint**, **PHPStan**, **Rector**. Reference configs in `snippets/`.

## 1. Laravel Pint (code style)

- Run: `./vendor/bin/pint`
- CI: `./vendor/bin/pint --test` (fails on style violation).
- Config `pint.json`: base preset `laravel` + reference rules (snippet `pint.json`): `declare_strict_types`, `date_time_immutable`, `final_class`, `fully_qualified_strict_types`, `global_namespace_import`, `mb_str_functions`, `ordered_class_elements`, `strict_comparison`, `protected_to_private`. Avoid further over-customization.
- `final_class` conflicts with Spatie guidelines ("no `final` by default", skill `code-style-spatie`): project picks one approach, applies consistently.

## 2. PHPStan (static analysis)

Laravel wrapper: `larastan/larastan`.
- Run: `./vendor/bin/phpstan analyse`
- Config `phpstan.neon` (snippet):
  - Level: existing projects may start at Level 5 and raise. Greenfield: set `level: max` immediately.
  - Reference: include larastan, `paths: [app]`, `inferPrivatePropertyTypeFromConstructor: true`.
  - Enable type checks + undefined-variable check.
  - Use PHPDoc (`@var`, `@return`, `@param`) where native typing is insufficient (array shapes, collection generics). On `level: max` main work: narrow `array` in public APIs via `@return array{...}`, `@phpstan-type`, typed DTOs.

## 3. Rector (instant upgrades & refactoring)

- Analyze (dry): `vendor/bin/rector process --dry-run`
- Apply: `vendor/bin/rector process`
- Config `rector.php` (snippet):
  - `withSetProviders(LaravelSetProvider::class)` + `withComposerBased(laravel: true)` (plugin `driftingly/rector-laravel`).
  - Sets: `SetList::TYPE_DECLARATION` + Laravel sets (`LARAVEL_CODE_QUALITY`, `LARAVEL_COLLECTION`, `LARAVEL_FACTORIES`, `LARAVEL_IF_HELPERS`, etc.).
  - `withPreparedSets(deadCode, codeQuality, typeDeclarations, privatization, earlyReturn)` + `withPhpSets()` for current language version.

## Workflow — strict order, before each commit/push

1. `vendor/bin/rector process` — refactor/modernize
2. `vendor/bin/pint` — format (cleans up after Rector)
3. `vendor/bin/phpstan analyse` — verify nothing broke
4. Tests (`phpunit` / `pest`)

Wrap chain in composer scripts (snippet `qa-composer-scripts.json`: `lint`, `lint:check`, `analyse`, `refactor`, `qa`, `ci:check`) or `Makefile` alias.

## Which snippet to open

| Situation | File |
|:---|:---|
| Code style / Pint rules | `pint.json` |
| PHPStan/Larastan, pick level | `phpstan.neon` |
| Rector with Laravel sets | `rector.php` |
| QA pipeline in composer scripts / CI | `qa-composer-scripts.json` |

## Quality checklist

- [ ] Pint: preset `laravel` + strict reference rules; CI `pint --test`
- [ ] PHPStan: `level: max` for greenfield (legacy: level-raise plan)
- [ ] Rector: TYPE_DECLARATION + Laravel sets + prepared sets (deadCode/codeQuality/typeDeclarations/privatization/earlyReturn)
- [ ] Run order: rector → pint → phpstan → tests (local + CI)
- [ ] `final_class` decision aligned with project style (see `code-style-spatie`)
- [ ] Composer scripts `lint` / `lint:check` / `analyse` / `refactor` defined

## Links

- Skill `code-style-spatie` (bucket php) — code style, `final_class` conflict
- Skill `laravel-testing` (bucket php) — tests + coverage gate at pipeline end
- Skill `php/php-upgrade-checklist` — same rector/phpstan pipeline on PHP version upgrade
- Skill `laravel-auth/laravel-security-audit` — "sharp edges" audit over static-analysis signals (requires this skill)
- Skill `php/pao` — compact JSON output of PHPStan/Rector/tests for AI agent
- Skill `php/named-arguments` — call style this pipeline checks/fixes
- https://laravel.com/docs/pint, https://phpstan.org, https://getrector.com

<!-- ru-source-sha256: 35cf3f9d8501d708903fff38e2f4ce69e35cc06439f812b2133a3c6341d6bac2 -->
