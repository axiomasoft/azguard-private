---
name: docker-dev-prod
bucket: devops
version: 0.3.0
description: "Split docker-compose into dev (build + bind-mount) and prod (prebuilt image + named volumes), local override, &app_env anchor"
risk: write
persona: operator
tags: ["docker", "devops", "compose", "laravel"]
requires: ["docker-php"]
produces_for: ["docker-services"]
outputs: ["docker-compose.yml", "docker-compose.prod.yml", "docker-compose.override.example.yml"]
snippets: ["docker-compose.yml", "docker-compose.dev.yml", "docker-compose.prod.yml", "docker-compose.override.yml", "docker-compose.override.example.yml", ".env.example", "Makefile"]
adapters: [claude, cursor, fable]
sha256: ""
---

One Laravel project, two compose files at repo root:

- **`docker-compose.yml` (dev)** — `build` from context (`dockerfile: docker/php/Dockerfile`) + bind-mount sources `./:/var/www/html`.
- **`docker-compose.prod.yml`** — prebuilt `image: <app>/app:latest` (`make image`) + named volumes for artifacts that bind-mount must not clobber: `public/build`, `public/filament` (if admin build exists), `vendor`.

## Rules

- Read .env from mounted file, NOT via build args (build-time values freeze in container). In `environment` pass only compose env vars (`${VAR}`); leave app-specific (e.g. `APP_URL`) to the mounted `.env`.
- YAML anchor `&app_env`: declare shared env block once on `app` (`environment: &app_env`); reuse in queue/scheduler/reverb/vite via `environment: *app_env` (extend with `<<: *app_env`).
- Local override: `docker-compose.override.yml` not committed; repo ships `docker-compose.override.example.yml`. WSL2/Linux bind-mount case: `user: "1000:1000"` (or PUID/PGID in env) for vite, else Permission denied on node_modules.

## Algorithm

1. Create `docker-compose.yml` (dev) per `docker-compose.dev.yml`: app with `build:` + bind-mount, healthcheck `/up`, anchor `&app_env`.
2. Create `docker-compose.prod.yml`: same services, `image: <app>/app:latest`, named volumes `frontend_build`, `vendor_data`; `APP_ENV: production`, `LOG_LEVEL: warning`.
3. Copy `docker-compose.override.example.yml` to repo; README: `cp docker-compose.override.example.yml docker-compose.override.yml`.
4. Move port/uid vars to `.env`/`.env.docker` with defaults (`${APP_PORT:-8080}`).
5. Run prod commands with explicit `-f docker-compose.prod.yml` (see `makefile` skill).

## Which snippet to open

| Situation | File |
|---|---|
| Minimal compose skeleton (legacy) | `snippets/docker-compose.yml` |
| Full dev-compose: build + bind-mount + `&app_env` anchor | `snippets/docker-compose.dev.yml` |
| Prod-compose: prebuilt image + artifact named volumes | `snippets/docker-compose.prod.yml` |
| Minimal local override (legacy) | `snippets/docker-compose.override.yml` |
| Local override template (WSL2, user 1000:1000) | `snippets/docker-compose.override.example.yml` |
| Base env vars | `snippets/.env.example` |
| Base make targets | `snippets/Makefile` |

## Quality checklist

- [ ] dev: `build` from context, prod: `image: <app>/app:latest` — no mixing
- [ ] .env mounted as file, values not frozen via build args
- [ ] shared env block via `&app_env` anchor, no per-service copy-paste
- [ ] prod: named volumes for `public/build`, `vendor` (and `public/filament` with admin build)
- [ ] `docker-compose.override.yml` in .gitignore, repo has `*.example.yml`
- [ ] ports and PUID/PGID parameterized with defaults `${VAR:-default}`

## Links

- https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/ — override-file mechanics
- https://docs.docker.com/reference/compose-file/fragments/ — YAML anchors in compose
- Related skills: `docker-php` (Dockerfile), `docker-services` (full service stack), `docker-postgres` (DB service + named volume), `docker-vite` (override for node_modules/WSL2), `makefile` (delegating Makefile)

<!-- ru-source-sha256: 2610e9e60aafce0d0dd34b991c8f874a05951d56ebb5db68794b1a9e7da6bfa2 -->
