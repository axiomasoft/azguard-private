---
name: laravel-packages
bucket: php-packages
version: 0.3.0
description: "Build Laravel packages: scaffold, tests, publish package skills to consumers via vendor:publish"
risk: write
persona: oss-dev
tags: ["php", "laravel", "package-dev", "skills"]
requires: []
produces_for: []
outputs: []
snippets: ["composer.json.stub", "ServiceProvider.stub.php", "config.stub.php", "boost-skill-publisher.php", "TestCase.stub.php"]
adapters: [claude, cursor, fable]
sha256: ""
---

Scope: create a Laravel package from scratch or extend one. Scaffold (composer.json, ServiceProvider, config, tests); if the package carries domain expertise, publish its own skills to consumers via `vendor:publish`. A domain package ships its skills with itself; consumer installs the package and gets agent skills via one artisan command.

## Inputs

- Package name (`vendor/package`) and purpose
- Target PHP/Laravel versions
- Whether the package has domain knowledge useful to the consumer's agent (usage patterns, migration scenarios, common errors)

## Algorithm

1. Scaffold from snippets: `composer.json.stub`, `ServiceProvider.stub.php`, `config.stub.php`, `TestCase.stub.php`. Replace placeholders with package name.
2. If the package publishes skills:
   - create `resources/skills/<package>-<skill>/SKILL.md` (+ `snippets/`). Skill folder name **must be prefixed with the package name** (avoid consumer collisions with other sources);
   - add publishing to ServiceProvider per `boost-skill-publisher.php`: agent-neutral layout (`.ai/skills/vendor/<package>/`) and/or flat for Claude Code (`.claude/skills/`);
   - publish tag is `<package>-skills`.
3. Full-cycle test: `composer install` in a test app → `php artisan vendor:publish --tag=<package>-skills` → verify `SKILL.md` landed at the expected path and the agent sees it.
4. Run package tests and linters (pest/phpunit, pint, phpstan).

## Outputs

- Working package scaffold
- `resources/skills/` with published skills (if applicable)
- ServiceProvider publishing with tag `<package>-skills`

## Quality checklist

- [ ] composer.json: PSR-4, supported PHP/Laravel versions, extra.laravel
- [ ] Published skills have valid frontmatter (`name`, `description`)
- [ ] Skill folder names prefixed with package name
- [ ] `vendor:publish --tag=<package>-skills` verified in a clean app
- [ ] Tests and static analysis green

## Links

- snippets/boost-skill-publisher.php
- snippets/ServiceProvider.stub.php
- snippets/composer.json.stub
- Related (upstream package-work skills from starters): `php-packages/laravel-package-scaffold`, `php-packages/laravel-package-service-provider`, `php-packages/laravel-package-testing`, `php-packages/laravel-package-docs`, `php-packages/laravel-package-release`, `php-packages/laravel-package-compatibility` (all external)
- `php/laravel` — architecture patterns inside the package; `php/laravel-structure` — class placement
- `botkit/botkit` — example domain package publishing skills to consumers
- `general/skills-ssot` — single source of truth for skills across multiple agents
<!-- ru-source-sha256: 4a22ad75ab97ca2de92770710741e2fad32b1393ad33199a7da86e59bfdb82e7 -->
