---
name: pao
bucket: php
version: 1.0.0
description: "Laravel PAO — agent-optimized PHP tool output: PHPUnit/Pest/Paratest/PHPStan/Rector/Artisan collapse to compact JSON (~20 tokens vs thousands) when run inside an AI agent. Install, verify, limits."
risk: write
persona: oss-dev
tags: [php, laravel, testing, tokens, agent-output]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel PAO — Agent-Optimized Output

PAO (https://github.com/laravel/pao) detects runs inside an AI agent (ENV: `CLAUDE_CODE`, `CURSOR_AGENT`, etc.) and replaces output with compact JSON (~20 tokens regardless of suite size). Human terminal sees normal output; only the agent env changes. Works with any PHP project (Laravel, Symfony, vanilla): PHPUnit, Pest, Paratest, PHPStan, Rector, Laravel Artisan.

## Input
- PHP ≥ 8.3 (hard package requirement).
- Composer project; activates via autoloader — no config needed.

## Steps
1. Check PHP version: `php -v` (≥ 8.3, else do NOT install PAO).
2. `composer require laravel/pao --dev`
3. Verify: run tests from an agent session — output must become JSON:

```json
{"result": "passed", "tests": 1002, "passed": 1002, "failed": 0, "duration_ms": 321}
```

On failure PAO keeps everything needed to fix — file, line, message:

```json
{
  "result": "failed",
  "tests": 1002, "passed": 1001, "failed": 1,
  "failures": [
    {"test": "UserTest::it_validates_email", "file": "tests/Unit/UserTest.php", "line": 45, "message": "Expected true, got false"}
  ]
}
```

4. If output not collapsed — verify tool ran via composer/vendor/bin (not global binary) and agent ENV present (`env | grep CLAUDE`).

## Quality checklist
- [ ] PHP ≥ 8.3 confirmed before install
- [ ] Agent test run returns JSON; terminal run returns normal output
- [ ] waaseyaa/agent-output NOT installed in parallel (duplicate output wrappers)

## Limits
- Custom `bin/check-*` scripts NOT covered by PAO — for those use waaseyaa/agent-output (NDJSON, −94.7%): https://jonesrussell.github.io/blog/agent-output-php-ci-tools/. Not adopted as a registry standard — PAO covers the main stack.

## Links
- https://github.com/laravel/pao
- https://laravel.com/blog/introducing-laravel-pao-cleaner-output-for-ai-agents
- https://laravel-news.com/pao-agent-optimized-output-for-php-testing-tools
- Related skills: `laravel-testing/laravel-testing` (PHPUnit/Pest, output collapsed by PAO), `php/static-analysis` (PHPStan/Rector — also under PAO wrapper), `general/context-economy` (general agent context economy)

<!-- ru-source-sha256: 870d164e8529095ef72d2833f558de178f9b30f66c4389b81a64a364937d417b -->
