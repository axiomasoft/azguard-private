---
name: docker
bucket: devops
version: 0.2.0
description: "Router for Dockerizing a Laravel/PHP app: directs to the right sub-skill (PHP image, Postgres, dev/prod compose split, full service stack, Vite HMR, Makefile). Use when the task involves Docker/Compose for this stack."
risk: read
persona: operator
tags: [docker, devops, router]
requires: []
produces_for: []
outputs: []
snippets: ["docker-expert.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

## What this is

A thin router skill: it does NOT containerize anything itself — it routes to the specialized sub-skill for the
need. Load the sub-skill on demand (progressive disclosure); don't pull the whole expertise into context upfront.

## Route (need → sub-skill)

| Need | Sub-skill |
|---|---|
| Production PHP image (alpine-fpm or all-in-one serversideup/php, entrypoint.d, healthcheck /up) | `devops:docker-php` |
| PostgreSQL in Docker (healthcheck, named volume, test-DB init) | `devops:docker-postgres` |
| Split compose into dev (build + bind-mount) and prod (prebuilt image) | `devops:docker-dev-prod` (requires docker-php) |
| Full Laravel stack from one image (app/queue/scheduler/reverb/docs) | `devops:docker-services` (requires docker-php + docker-postgres) |
| Vite dev server as a separate compose service (HMR) | `devops:docker-vite` |
| Makefile as the single entrypoint (help, .PHONY, docker aliases) | `devops:makefile` |

Full-stack build order: `docker-php` → `docker-postgres` → `docker-dev-prod` → `docker-services`
(+ `docker-vite`, `makefile` as needed). The DAG is encoded in each sub-skill's `requires`/`produces_for`.

## Deep expertise (on demand)

General principles (image optimization, security hardening, multi-stage, orchestration) live in
`snippets/docker-expert.md` — read it only when a sub-skill isn't enough.
