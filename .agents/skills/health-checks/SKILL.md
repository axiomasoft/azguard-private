---
name: health-checks
bucket: laravel-testing
version: 0.1.0
description: "spatie/laravel-health in Laravel: custom Check classes (disk, queue, scheduler heartbeat, TCP port, cache, DB) via Health::checks(); /health endpoint (live/ready, JSON/Filament); scheduled runs, failure notifications. Activate on: health check, liveness/readiness, /health, spatie health, disk/queue/cache/heartbeat checks."
risk: write
persona: oss-dev
tags: [laravel, health, monitoring, spatie, liveness, readiness, observability]
requires: []
produces_for: []
outputs: []
snippets: [ExampleCheck.php, config-health.php]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: spatie/laravel-health — availability checks

Declarative Laravel health checks on `spatie/laravel-health`: each check = one Check class returning `Result::ok()`/`Result::failed()`; all registered in one list; served over HTTP (`/health`), run on schedule, notifies on failure.

Package install/config publish NOT covered here: `composer require spatie/laravel-health` + `php artisan vendor:publish --tag="health-config"` (see Links). This skill = the check pattern and its wiring.

## Algorithm

1. **One class per check.** `app/Health/Checks/`, `final`, extends `Spatie\Health\Checks\Check`, implements `run(): Result`. Class name = what it checks (`DatabaseConnectionCheck`, `QueueConnectionCheck`, `DiskWriteCheck`). One check = one responsibility.
2. **`run()` contract: always `Result`, never throw out.** Wrap risky ops (I/O, network, drivers) in `try { ... } catch (Throwable $e) { return Result::make()->failed($e->getMessage()); }`. Success `Result::make()->ok('...')`; intermediate `->warning('...')`; failure `->failed('...')`. Message short + human-readable; diagnostics in `->meta([...])`.
3. **Read config via `config()` with defaults, no hardcode.** Host/port/timeout/thresholds from `config('health.<service>.*')` or `config('<service>.*')` with `default:`. Thresholds (max heartbeat delay, socket timeout) → env via `config/health.php` sections.
4. **Active check, not config read.** Check must actually touch the resource:
   - **DB** — `DB::connection()->select('select 1')`;
   - **cache** — `Cache::put` unique key → `Cache::get` → compare → `Cache::forget`;
   - **disk** — `Storage::disk($d)->put` temp file (UUID) → `get` → compare → `delete`;
   - **service TCP port** — `@fsockopen($host, $port, $errno, $errstr, $timeout)`, check `is_resource`, `fclose`;
   - **queue** — determine default connection driver; for `database` ensure `jobs` table exists (`Schema::hasTable`).
5. **Scheduler heartbeat — indirect check.** `routes/console.php` writes timestamp each minute (`cache()->forever('health:scheduler:last_heartbeat', now()->toIso8601String())`); `SchedulerHeartbeatCheck` compares its age to threshold `health.scheduler.max_delay_minutes`; stale → `failed`. Same trick for queue/workers: worker writes heartbeat, check reads.
6. **Parameterized checks — static factory constructor.** For multiple targets (several disks): private ctor + `public static function forDisk(string $disk, string $directory): self`. On registration set unique name: `DiskWriteCheck::forDisk('media', 'health/media')->name('disk_media_write')`.
7. **Registration — one `Health::checks([...])`** in bootstrap provider (`AppServiceProvider::boot()` or dedicated `HealthServiceProvider`). Instantiate via `::new()` (or factory). Attach `->name(...)`, `->if(...)`/`->unlessEnvironment(...)` for conditional run. Package config (`config/health.php`) sets result store, notifications, check sections, `secret_token`.
8. **HTTP endpoint — two levels.**
   - **liveness** "process alive" — `SimpleHealthCheckController` on `/health/live` (no heavy checks);
   - **readiness** "ready to serve" — `HealthCheckJsonResultsController` on `/health/ready`, returns JSON of last results; failure response code set by `health.json_results_failure_status` (default 503), read by balancer/probe.
   Dashboard — `Route::get('/health', HealthCheckResultsController::class)` (Blade) or **Filament** via `spatie/laravel-health` Filament page.
9. **Run schedule.** `routes/console.php`: `Schedule::command('health:check')->everyMinute();` — runs checks, saves to store. Readiness endpoint returns *stored* result, not run-per-request (matters for probe under load).
10. **Notifications.** `config/health.php` → `notifications`: enable `enabled` (env), pick channels (`mail`, `slack`), throttle (`throttle_notifications_for_minutes`), `only_on_failure`. Channel gets `CheckFailedNotification`. External monitoring — `oh_dear_endpoint` or heartbeat URL (`scheduler.heartbeat_url`, `horizon.heartbeat_url`) pinged on success.

## Standard check matrix (generic service)

| Check | Touches | Class / technique |
|:---|:---|:---|
| Database | `select 1` via default connection | `DatabaseConnectionCheck` |
| Cache | put/get/forget unique key | `CacheStoreCheck` |
| Disk | put/get/delete temp file (per-disk factory) | `DiskWriteCheck::forDisk(...)` |
| Queue | default connection driver + `jobs` table present | `QueueConnectionCheck` |
| Scheduler heartbeat | cache mark age vs threshold | `SchedulerHeartbeatCheck` |
| Service TCP port | `fsockopen($host,$port,...,$timeout)` | `ServiceTcpConnectionCheck` |
| Config consistency | compare client/server param pairs | `ServiceClientConfigCheck` |
| External provider | at least one API key set (`config('...providers')`) | `ConfiguredProvidersCheck` |

Built-in package checks (`UsedDiskSpaceCheck`, `DatabaseCheck`, `DebugModeCheck`, `EnvironmentCheck`, `OptimizedAppCheck`, `ScheduleCheck`, `HorizonCheck`, `RedisCheck`) — use as-is, don't duplicate with custom. Write custom only for what's not built in.

## Antipatterns

- `run()` throws out instead of `Result::failed()` — whole run fails entirely.
- Check reads only config (`config(...) !== null`), doesn't touch resource — "green" while service is down.
- Hardcoded host/port/threshold in class instead of `config(...)` with default — not portable across envs.
- Endpoint runs checks synchronously per HTTP probe request — DoS under load; serve stored result, run on schedule.
- Temp artifacts (file/cache key) not removed after check — garbage + false positives.
- Liveness probe tied to DB/queue: external dep down → k8s endlessly restarts a live pod. Liveness ≠ readiness.

## Which snippet

| Situation | File |
|:---|:---|
| Write custom check (`run(): Result`, try/catch, meta, factory) | `snippets/ExampleCheck.php` |
| Register checks, configure store/notifications/sections/endpoint/schedule | `snippets/config-health.php` |

## Quality checklist

- [ ] Each check = separate `final` class in `app/Health/Checks/`, extends `Check`, implements `run(): Result`
- [ ] `run()` always returns `Result` (ok/warning/failed); risky ops in `try/catch (Throwable)`
- [ ] Check actually touches resource (put/get, `select 1`, `fsockopen`), not just config read
- [ ] Host/port/timeout/thresholds from `config(...)` with defaults + env, not hardcoded
- [ ] Temp artifacts (file, cache key) removed after check
- [ ] Diagnostics in `->meta([...])`, `ok/failed` message short + human-readable
- [ ] Parameterized checks — private ctor + static factory + unique `->name()`
- [ ] All checks in one `Health::checks([...])` in bootstrap provider
- [ ] Has liveness (`SimpleHealthCheckController`) and readiness (`HealthCheckJsonResultsController`); liveness not tied to external deps
- [ ] `health:check` scheduled; endpoint serves stored result, not run-per-request
- [ ] Failure notifications configured (channel, throttle, `only_on_failure`); endpoint/Oh Dear protected by `secret_token` if needed
- [ ] Built-in package checks not duplicated by custom

## Links

- https://spatie.be/docs/laravel-health/v1/introduction
- https://spatie.be/docs/laravel-health/v1/basic-usage/configuring-checks
- https://spatie.be/docs/laravel-health/v1/basic-usage/creating-custom-checks
- https://spatie.be/docs/laravel-health/v1/available-checks/overview
- https://spatie.be/docs/laravel-health/v1/basic-usage/getting-results
- snippets/ExampleCheck.php
- snippets/config-health.php
- Related skills: `architect/observability-design` (logging/metrics/tracing, SLO/SLI — health as part of prod readiness), `operator/runbook` and `operator/incident-response` (what to do on check failure), `devops/docker-services` (healthcheck `/up` and `depends_on: service_healthy`), `laravel-extras/spatie-settings` (another spatie config layer)

<!-- ru-source-sha256: af7399f06b9c268a2b33646d0ff90817ebcfeba57bf6428510a2cbbc6261d927 -->
