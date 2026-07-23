---
name: makefile
bucket: devops
version: 0.2.0
description: "Makefile as single entrypoint: help, .PHONY, Docker command aliases; delegating root Makefile pattern with catch-all to docker/Makefile"
risk: write
persona: operator
tags: ["make", "devops", "docker"]
requires: []
produces_for: ["docker-dev-prod", "docker-services"]
outputs: ["Makefile", "docker/Makefile"]
snippets: ["Makefile.root", "Makefile.docker"]
adapters: [claude, cursor, fable]
sha256: ""
---

Makefile = single entrypoint for local project management; hides long Docker/Composer/pnpm commands.

## Rules

- **Documented**: `make help` (default first target) lists commands with descriptions.
- **Declarative**: targets are simple aliases; put complex logic in bash scripts.
- **`.PHONY`**: declare all targets so Make doesn't confuse them with same-named files.
- **`.env`**: `ifneq (,$(wildcard ./.env)) include .env / export endif` — env vars available in commands.

**Delegating Makefile pattern**: root Makefile holds only `help` + catch-all:

```makefile
%:
	@$(MAKE) -f docker/Makefile $@
```

All docker logic isolated in `docker/Makefile`; any command (`make up`, `make migrate`, `make test`) runs from repo root.

## Steps

1. Root Makefile per `Makefile.root`: `MAKEFLAGS += --no-print-directory`, `help`, catch-all `%`.
2. `docker/Makefile` per `Makefile.docker`: var `DOCKER_COMPOSE_DEV` wires env-files (`--env-file .env --env-file .env.docker` if second exists; with explicit `--env-file` compose stops auto-reading `.env`).
3. Standard targets: `up/down/build/restart/ps/logs/shell/artisan/migrate/test/db-create-test`; prod targets with explicit `-f docker-compose.prod.yml` and `prod:` prefix (escape colon in .PHONY and targets: `prod\:migrate`).
4. List all targets in `.PHONY`; `help` first.
5. Parameterized calls via `ARGS`: `make artisan ARGS="migrate --seed"`.

## Snippet selection

| Situation | File |
|---|---|
| Root delegating Makefile (help + catch-all) | `snippets/Makefile.root` |
| Docker logic: up/down/build/shell/logs/migrate/test/db-create-test, env-files | `snippets/Makefile.docker` |

## Quality checklist

- [ ] `make help` first target, describes all commands
- [ ] all targets in `.PHONY` (including escaped `prod\:*`)
- [ ] root Makefile has no docker logic — only delegation
- [ ] `--env-file` also lists `.env`: with explicit flag compose won't read it itself
- [ ] prod targets always with explicit `-f docker-compose.prod.yml`
- [ ] complex logic (test DB creation etc.) in bash scripts, Make only calls them

## Links

- https://www.gnu.org/software/make/manual/make.html#Phony-Targets — .PHONY
- https://www.gnu.org/software/make/manual/make.html#Last-Resort — match-anything (catch-all) rules
- Related skills: `docker-dev-prod` (compose files Makefile invokes), `docker-services` (stack mgmt: logs/restart/workers), `docker-postgres` (db-create-test target), `db-test-preflight` (`test-preflight` target as `test` dependency)

<!-- ru-source-sha256: bb9cd82ebe20fe672ec61fd1c528b1b996e3e66d8cfa92b01c9eb53f722e89d4 -->
