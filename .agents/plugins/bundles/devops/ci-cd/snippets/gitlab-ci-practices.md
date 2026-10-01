# GitLab CI/CD Best Practices

This document describes standards and best practices for writing `.gitlab-ci.yml` pipelines for projects.

## Basic Principles

1.  **Speed:** The pipeline should move as quickly as possible. Use dependency caching and Docker images.
2.  **Reliability:** Tests and linters should be executed in isolation and not depend on the state of the environment.
3.  **Security:** Secrets and passwords should only be stored in GitLab CI/CD Variables (preferably with flags `Masked` and `Protected`), never in code.

## Pipeline Structure (Stages)

Standard division of stages:
```yaml
stages:
  - lint
  - test
  - build
  - deploy
```

## Rules (Rules) instead Only/Except

Use `rules` to control the launch of jobs. This is a modern and more flexible mechanism compared to outdated ones `only` and `except`.

```yaml
.standard_rules:
  rules:
    # Run when Merge Request
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    # Run on default branch (main/master)
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
```

## Caching (Caching)

Cache dependency folders (for example, `vendor/` for PHP, `node_modules/` for Node.js, `.venv/` for Python), to speed up the build.
It is better to bind the cache key to the lock file (`composer.lock`, `package-lock.json`, `poetry.lock`).

```yaml
cache:
  key:
    files:
      - composer.lock
  paths:
    - vendor/
```

## Docker in Docker (dind)

For assembly Docker-images inside GitLab CI use the service `docker:dind`.
Try to use `docker buildx` with layer caching (`--cache-from` and `--cache-to`).

```yaml
build_image:
  stage: build
  image: docker:24.0.5
  services:
    - docker:24.0.5-dind
  variables:
    DOCKER_TLS_CERTDIR: "/certs"
  script:
    - docker login -u $CI_REGISTRY_USER -p $CI_REGISTRY_PASSWORD $CI_REGISTRY
    - docker build --pull -t $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA .
    - docker push $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA
```

## Optimization and Tricks

-   **Interruptible:** Install `interruptible: true` for stages `lint` and `test`. If a developer pushes a new commit before the old one's pipeline ends, the old pipeline will be reverted, saving runners' resources.
-   **Needs:** Use keyword `needs`, to build Directed Acyclic Graphs (DAG). This allows jobs to start immediately after the desired previous jobs have completed, without waiting for the entire stage to finish.

```yaml
test_backend:
  stage: test
  needs: ["lint_backend"]
  script:
    - vendor/bin/phpunit
```

## Coverage-gate job

Coverage threshold - separate job on top of the general `.php-base` (assembly PHP-extensions,
waiting Postgres, migrations). Parser/threshold config - in skill `laravel-testing/laravel-testing`
(`coverage.php` + `check-php-coverage-gate.php`); CI only calls them with env-thresholds.

```yaml
variables:
  COVERAGE_GATE_MODE: hard         # hard => exit 1 below threshold; report/soft — do not block
  COVERAGE_GLOBAL_MIN: "70.0"      # total minimum by row
  COVERAGE_CRITICAL_MIN: "55.0"    # toughened for critical directories (Actions/Policies/Services)

.php-base:
  stage: test
  image: php:8.3-cli-bookworm
  services:
    - { name: postgres:16-alpine, alias: postgres }
  cache:
    key: { files: [composer.lock] }
    paths: [vendor/]
  before_script:
    - apt-get update -qq && apt-get install -y -qq git unzip libpq-dev libicu-dev $PHPIZE_DEPS postgresql-client
    - docker-php-ext-install -j "$(nproc)" intl pdo_pgsql zip
    - pecl install pcov && docker-php-ext-enable pcov   # pcov faster xdebug
    - printf 'pcov.directory=app\n' > /usr/local/etc/php/conf.d/pcov.ini
    - curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --no-interaction --prefer-dist
    - touch .env && php artisan key:generate --force --no-interaction
    - until pg_isready -h postgres -U postgres; do sleep 1; done
    - php artisan migrate --force --no-interaction

php-coverage:
  extends: .php-base
  interruptible: true
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
  script:
    - php -d memory_limit=512M artisan test --coverage --coverage-clover=coverage/clover.xml
    - php scripts/check-php-coverage-gate.php
  artifacts:
    when: on_success
    paths: [coverage/clover.xml]
    expire_in: 1 week
```

Principle: fast `php-tests` (uncoated) gives early fail; `php-coverage` separate
job believes Clover and goes down merge only when `hard` below threshold. Coverage - bottom
risk boundary, not goal (test quality - `quality/mutation-testing`).
