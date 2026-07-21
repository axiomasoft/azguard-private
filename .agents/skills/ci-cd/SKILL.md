---
name: ci-cd
bucket: devops
version: 0.4.0
description: "GitHub Actions / GitLab CI pipelines for Laravel: lint, tests, build, release + security gates (SAST, dependency audit, image scan) + coverage-gate in CI (Clover threshold, jobs)"
risk: write
persona: operator
tags: ["ci", "github-actions", "gitlab-ci", "laravel", "devops", "release", "security", "coverage"]
requires: []
produces_for: ["release-engineering"]
outputs: []
snippets: ["github-actions-laravel.yml", "github-actions-release.yml", "github-actions-docker.yml", "github-actions-security.yml", "github-actions-coverage-gate.yml", "gitlab-ci-practices.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

CI/CD via GitHub Actions.

## Pipeline

1. Lint + static analysis
2. Unit/feature tests
3. Build Docker image on main
4. Release workflow on tag
5. Security gates — separate job; don't block merge on warning, block on high/critical

## Security gates

Three independent slices, each a separate step/job (snippet `github-actions-security.yml`):

- **SAST (code).** Static analysis already in step 1 — add taint mode: PHPStan at max rules, where possible `psalm --taint-analysis` (unvalidated input flow into SQL/output). Algorithm/config → skill `php/static-analysis`.
- **Dependencies.** `composer audit` (PHP) and `npm audit --audit-level=high` (JS) in CI; Dependabot/Renovate for auto-PRs on vulnerable versions. Deeper (SBOM, update policy) → skill `oss-dev/dependency-audit`.
- **Image.** `trivy image` (or `aquasecurity/trivy-action`) on the image built in step 3: CVEs in base layers and OS packages. Image built per skill `devops/docker-php`.

Gate rule: `exit-code: 1` only on HIGH/CRITICAL; LOW/MEDIUM → report, not block.

## Coverage-gate in CI

Coverage threshold checked in a **separate job**, doesn't block fast test run: first `lint`+`tests` (fast fail), then coverage-job collects Clover and runs the gate. Two independent thresholds — global (`COVERAGE_GLOBAL_MIN`, typically 70%) and stricter for critical dirs (`COVERAGE_CRITICAL_MIN`, typically 55%; covers `Actions/Policies/Services/Middleware`).

**Don't rewrite the parser.** Config (`coverage.php`), gate script (`check-coverage-gate.php`, modes `report|soft|hard`, per-file critical-zone check) and composer scripts (`test:coverage`, `test:coverage:gate`, `test:critical`) live in skill `laravel-testing/laravel-testing` (its snippets `coverage-gate-config.php`, `check-coverage-gate.php`, `composer-test-scripts.json`). CI only **calls** them.

CI-job algorithm:

1. PHP with coverage driver (**pcov** faster than xdebug; `pcov.directory=app`).
2. On Postgres projects — wait for DB (`until pg_isready ...; do sleep 1; done`) and run migrations before tests (`php artisan migrate --force`).
3. `php artisan test --coverage --coverage-clover=coverage/clover.xml`.
4. `php scripts/check-php-coverage-gate.php` (or `composer test:coverage:gate`) with env `COVERAGE_GATE_MODE=hard`, `COVERAGE_GLOBAL_MIN`, `COVERAGE_CRITICAL_MIN`.
5. Store `coverage/clover.xml` as artifact (report/trend).

- **GitHub Actions** — snippet `github-actions-coverage-gate.yml`.
- **GitLab CI** — pattern `.php-base` (build PHP extensions, `pg_isready` wait, migrations) + job `php-coverage` in snippet `gitlab-ci-practices.md` (section "Coverage-gate job").

Gate mode: `hard` (blocks merge) on default branch and MR; `report`/`soft` for informational runs. Threshold is a risk floor, not a goal: test quality measured by mutation testing (`quality/mutation-testing`), not line %.

## Links

- Related skills: `oss-dev/github-flow` (Issue→PR→Tag→Release chain), `oss-dev/release-engineering` (release pipeline, CHANGELOG, SemVer), `devops/gitops` (branch model, protection), `php/static-analysis` (Pint/PHPStan/Rector + taint in CI), `oss-dev/dependency-audit` (dependency audit, SBOM, update policy), `laravel-testing/laravel-testing` (test run **+ coverage-gate config/script**, reused by this job), `quality/test-strategy` (coverage policy and pyramid the threshold relies on), `quality/mutation-testing` (Infection gate in CI), `quality/playwright-e2e` (E2E in CI), `devops/docker-php` (image for build/scan step)
- snippets/github-actions-security.yml — security-job: composer/npm audit + Trivy image scan
- snippets/github-actions-coverage-gate.yml — coverage-job: pcov + `test --coverage` + gate script with global and critical-dir thresholds
- snippets/gitlab-ci-practices.md — `.gitlab-ci.yml` practices + `.php-base`/`php-coverage` job for coverage-gate

<!-- ru-source-sha256: c7e46c0ea646c7417cd176c55cf66fac16b266caeaf509910751145e6c2934dd -->
