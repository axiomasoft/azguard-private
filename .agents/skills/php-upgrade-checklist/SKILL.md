---
name: php-upgrade-checklist
bucket: php
version: 0.1.0
description: "PHP version upgrade checklist (minor/major): sync image/CI, composer update + clear deprecations, phpstan baseline, full suite, update rector sets, review RFCs for incompatibilities. Activate before bumping PHP version."
risk: draft
persona: oss-dev
tags: [php, upgrade, version, rector, phpstan, composer]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# PHP Upgrade Checklist

Activate before raising minor/major PHP version (constraint in `composer.json`, image, CI). Not for one-off extension install. Version-neutral (procedure, not specific version; version-specific PHP/framework rules → Laravel Boost `boost:update`).

## Steps

1. **Sync version everywhere.** `composer.json` (`require.php`), image (Dockerfile / base image), CI matrix — same minimum version.
2. **Image & extensions.** Update PHP image; rebuild extensions if needed (`docker-php-ext-install` / `pecl`); verify all required extensions present in new version.
3. **Dependencies.** `composer update` (in target environment); clear package deprecations; packages without new-version support — update or replace; commit `composer.lock`.
4. **Static analysis.** Run phpstan/psalm. Drop baseline entries **only together with a code fix** — do not zero out baseline for green.
5. **Full test run.** Whole suite on new version (not only targeted) — type/core-behavior changes surface in integration tests.
6. **Rector.** Update sets (incl. Laravel sets for the new version), run, review autofix diff.
7. **RFCs & incompatibilities.** Review target-version changelog/RFC: type changes, deprecated core behavior, dependency incompatibilities (incl. framework components). Record findings in the task.

## Quality checklist

- [ ] PHP version synced in `composer.json`, image, CI.
- [ ] Image/extensions updated and build.
- [ ] `composer update` passed, package deprecations cleared, lock committed.
- [ ] phpstan/psalm: baseline dropped only with code fix.
- [ ] Full suite green on new version.
- [ ] Rector sets updated and run.
- [ ] Target-version RFCs/incompatibilities reviewed and accounted for.

## Links

- `static-analysis` (php) — phpstan/pint/rector config.
- `laravel-package-compatibility` (php) — multi-version support in a package (adjacent: package, not app upgrade).
- `laravel-testing/laravel-testing` — full suite run on new version after bump.
- `devops/docker-php` — PHP image & extensions update.
- `php/pao` — compact rector/phpstan/test output when running upgrade from agent.
- Version-specific PHP/framework rules — Laravel Boost (`boost:update`).

<!-- ru-source-sha256: 4445b853daddb72282c157e7d22277f54c5f4a4c00530a0992c27691075d689d -->
