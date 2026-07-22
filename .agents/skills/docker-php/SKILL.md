---
name: docker-php
bucket: devops
version: 0.3.0
description: "Production PHP Docker image: alpine-fpm or all-in-one serversideup/php (FPM+Nginx), entrypoint.d hooks, healthcheck on /up"
risk: write
persona: operator
tags: ["docker", "php", "devops", "laravel"]
requires: []
produces_for: ["docker-dev-prod", "docker-services", "docker-vite"]
outputs: ["infra/docker/php/Dockerfile", "infra/docker/php/php.ini"]
snippets: ["Dockerfile.php-fpm", "Dockerfile.php-cli", "Dockerfile.serversideup", "entrypoint-hook.sh", "php.ini.production", "opcache.ini"]
adapters: [claude, cursor, fable]
sha256: ""
---

Production PHP Docker image (Laravel/PHP). Two patterns:

1. **alpine-fpm** (`php:8.3-fpm-alpine`) — minimal FPM, web server in separate container.
2. **all-in-one** (`serversideup/php:8.3-fpm-nginx`) — FPM+Nginx, S6-overlay, SIGTERM handling, `PUID`/`PGID` for bind-mount perms. One image reused for app/queue/scheduler/reverb/vite — only `command` changes.

serversideup `/etc/entrypoint.d/` hooks: executable `NN-*.sh` copied here auto-run at start (number order). Typical use: set perms on dirs appearing on bind-mount after build (e.g. `public/media`).

## Steps

1. Choose pattern: separate fpm (`Dockerfile.php-fpm`) or all-in-one (`Dockerfile.serversideup`).
2. Install system deps + PHP extensions (`docker-php-ext-install`, `pecl` for imagick/redis).
3. serversideup: if frontend builds in this image — add Node.js + pnpm (image reused by vite/docs services).
4. Add entrypoint hooks: `COPY ... /etc/entrypoint.d/20-*.sh` + `chmod +x` (see `entrypoint-hook.sh`).
5. opcache (`opcache.ini`) + production `php.ini.production`; upload limits (upload_max_filesize etc.) in separate conf.d file.
6. End Dockerfile with non-root user (`USER www-data` for serversideup).
7. compose healthcheck on Laravel `/up`:
   `test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:8080/up >/dev/null || exit 1"]` with `start_period: 40s`.

## Snippet selection

| Situation | File |
|---|---|
| Minimal FPM, nginx separate container | `snippets/Dockerfile.php-fpm` |
| CLI image for artisan/queue (alpine) | `snippets/Dockerfile.php-cli` |
| All-in-one FPM+Nginx, one image for stack | `snippets/Dockerfile.serversideup` |
| Auto-run script at container start (perms, init) | `snippets/entrypoint-hook.sh` |
| Production PHP settings | `snippets/php.ini.production` |
| OPcache | `snippets/opcache.ini` |

## Quality checklist

- [ ] non-root user (`www-data` / custom) in final Dockerfile layer
- [ ] opcache enabled in production
- [ ] healthcheck set (Laravel — `/up`)
- [ ] entrypoint hooks executable (`chmod +x`) and idempotent (`set -eu`, check `id -u` before chown)
- [ ] .dockerignore current (node_modules, vendor, .git)
- [ ] one image reused for app/queue/scheduler (serversideup pattern)

## Links

- https://serversideup.net/open-source/docker-php/docs — serversideup/php (entrypoint.d, PUID/PGID, S6-overlay)
- https://laravel.com/docs/deployment#the-health-route — Laravel `/up`
- Related skills: `docker-dev-prod` (compose layout), `docker-services` (stack from one image), `docker-vite` (Node+pnpm in same image for frontend), `laravel-testing/health-checks` (what `/up` returns, liveness/readiness)

<!-- ru-source-sha256: 2858a4cd0fbb02d661a80c028e85e7f6539ed00a4c58055bb8a9078f4ad33e3c -->
