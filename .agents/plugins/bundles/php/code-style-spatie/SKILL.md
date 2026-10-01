---
name: code-style-spatie
bucket: php
version: 0.1.1
description: "Use when applying adopted Spatie PHP style: types, early returns, Laravel conventions."
risk: write
persona: oss-dev
tags: ["php", "laravel", "code-style", "spatie"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

## Context

Guidelines Spatie for Laravel/PHP-code: apply when creating, editing, reviewing and refactoring `.php` and `.blade.php` files - controllers, models, routes, configs, validation, migrations, tests. Main principle: **Laravel-conventions before PSR-12** — if Laravel there is a documented way, use it; deviate only with clear justification.

## Algorithm

1. Identify artifact (controller, route, config, model, Blade, test).
2. Open `references/spatie-laravel-php-guidelines.md`, sections on the topic of the artifact.
3. Apply: first Laravel-convention, then standards PHP, then section rules.
4. If there is a conflict with the conventions of a specific project, follow the project, but consistently.

### Key rules

- Typed properties, not dockblocks; obvious return types, including `void`.
- Short nullable: `?string`, not `string|null`.
- Constructor property promotion, when all properties can be promoted.
- One trait for one `use`.
- Early returns, avoid `else`; curly braces always, even for a single expression.
- Happy path last: error handling first, success last.
- String interpolation (`"Hi, {$name}"`) instead of concatenation.
- Routes: kebab-case URL, camelCase route names and parameters, tuple-notation `[Controller::class, 'method']`.
- Resource-controllers - plural (`PostsController`), only CRUD-methods; not-CRUD actions - separate controller.
- Validation: massive rules notation (`'email' => ['required', 'email']`).
- `config()` instead `env()` out `config/`; service configs - in `config/services.php`.
- Translations: `__()`, not `@lang`.
- Enum-class values and constants - PascalCase.

### Don't

- Docblocks with full typing (except generics/array shapes and cases where a description is needed).
- FQN in dockblocks - always import classes.
- `final` / `readonly` «default».
- `else`, when working early returns; spaces after Blade-directives (`@if($x)`, not `@if ($x)`).
- `down()`-methods in migrations - only `up()`.

> **Conflict with static-analysis.** Rule «do not use `final` default» contradicts `pint.json`-skill config `static-analysis` (rules `final_class` / `final_internal_class`). This is a conscious fork: the project chooses ONE of two - either Spatie-style without total `final`, or Pint with `final_class` — and follows the selection consistently across the codebase.

## When to open which snippet

| Situation | File |
|:---|:---|
| Any work with PHP/Blade-code: complete set of rules (typing, dockblocks, control flow, routes, configs, enum, Blade, validation, naming) | `references/spatie-laravel-php-guidelines.md` |

No snippets - all details in reference-file.

## Quality checklist

- [ ] Typed properties, parameters and returns (including `void`); no extra dockblocks
- [ ] No `else` where possible early returns; happy path last
- [ ] All control structures with curly braces
- [ ] Routes: kebab-case URL + camelCase names + tuple-notation
- [ ] `env()` only in `config/`; transfers via `__()`
- [ ] Validation rules in massive notation
- [ ] Question `final`/`readonly` is consistent with the config Pint project (see warning above)

## Links

- `references/spatie-laravel-php-guidelines.md` — full reference
- https://spatie.be/guidelines — original source
- `general/naming-conventions` — naming *entities* (Action/Repository/DTO/VO/Enum, parsing `findByXOrFail`); this skill sets naming routes/controllers, that is classes and methods
- Skill `static-analysis` (bucket php) — Pint/PHPStan/Rector configs, conflict `final_class`
