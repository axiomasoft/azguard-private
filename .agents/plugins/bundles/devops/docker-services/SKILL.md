---
name: docker-services
bucket: devops
version: 0.1.0
description: "Full Laravel service stack from one image: app (fpm-nginx), queue, scheduler, reverb, optional docs; healthcheck /up and depends_on service_healthy"
risk: write
persona: operator
tags: ["docker", "devops", "compose", "laravel", "queue", "websocket"]
requires: ["docker-php", "docker-postgres"]
produces_for: []
outputs: ["docker-compose.yml (сервисы app/queue/scheduler/reverb/docs)"]
snippets: ["services.yml", "healthchecks.yml"]
adapters: [claude, cursor, fable]
sha256: ""
---

Full Laravel stack from **one image** (`docker-php`, serversideup fpm-nginx pattern): each process is its own container, differing only by `command`.

| Service | Command | Port |
|---|---|---|
| app | image default (FPM + Nginx) | 8080 |
| queue | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` | — |
| scheduler | `php artisan schedule:work` | — |
| reverb | `php artisan reverb:start --host=0.0.0.0 --port=8000` (websocket) | 8000 |
| docs (opt.) | build init-container (`restart: "no"`) + nginx:alpine serves static | 4173 |

Stack wiring:
- **app healthcheck** via Laravel endpoint: `curl -fsS http://127.0.0.1:8080/up` with `start_period: 40s`.
- **Start order** via `depends_on: { pgsql: { condition: service_healthy } }`; workers additionally wait for healthy app. docs-nginx waits init-container via `condition: service_completed_successfully`.
- **Shared env** — YAML anchor `&app_env` on app; other services use `environment: *app_env` (extend with `<<: *app_env`).

## Algorithm
1. Build app service: image from `docker-php`, healthcheck `/up`, `stop_signal: SIGTERM`, declare anchor `&app_env`.
2. Add queue/scheduler/reverb per `services.yml`: same build/image, own `command`, `environment: *app_env`, `depends_on` on healthy app+pgsql(+redis).
3. For reverb expose `${REVERB_SERVER_PORT:-8000}` outward (websocket clients connect from host).
4. Optional docs: init-container builds static (`restart: "no"`), nginx-alpine serves it read-only.
5. For prod add worker healthchecks (`pgrep -af 'artisan queue:work'`) — see `healthchecks.yml`.
6. Manage via make targets `logs:core`, `*-restart`, `workers:restart` (skill `makefile`).

## Which snippet
| Situation | File |
|---|---|
| Bring up whole stack (app+queue+scheduler+reverb+docs) | `snippets/services.yml` |
| healthcheck and depends_on patterns (app, workers, init-container) | `snippets/healthchecks.yml` |

## Quality checklist
- [ ] one image for all PHP services, difference only in `command`
- [ ] app: healthcheck on `/up`, `start_period` sufficient for warmup
- [ ] workers depend on healthy app/pgsql/redis, not just "started"
- [ ] env via anchor `&app_env`, no duplicated blocks
- [ ] `stop_signal: SIGTERM` on app/reverb — graceful shutdown
- [ ] init-containers: `restart: "no"` + `service_completed_successfully` on consumers
- [ ] queue:work with `--max-time` — worker periodically restarts and picks up new code

## Links
- https://docs.docker.com/reference/compose-file/services/#depends_on — depends_on conditions
- https://laravel.com/docs/queues#running-the-queue-worker, https://laravel.com/docs/reverb — workers and Reverb
- Related skills: `docker-php` (image), `docker-dev-prod` (dev/prod layout), `docker-postgres` (healthy pgsql), `makefile` (stack management), `laravel-testing/health-checks` (`/up` content, liveness/readiness), `laravel-data-layer/laravel-broadcasting` (reverb service, websockets)

<!-- ru-source-sha256: dc2386642d9adeb0ca39ec01c5505cf1bb6e5bfd8050662dcbc20312d9a8caa9 -->
