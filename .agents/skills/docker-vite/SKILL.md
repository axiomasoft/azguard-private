---
name: docker-vite
bucket: devops
version: 0.3.0
description: "Vite dev server as separate compose service for Laravel: named volume for node_modules, HMR via localhost, host 0.0.0.0"
risk: write
persona: operator
tags: ["docker", "devops", "vite", "frontend", "laravel"]
requires: []
produces_for: ["docker-dev-prod"]
outputs: ["docker-compose.yml (vite service)", "vite.config.js (server block)"]
snippets: ["Dockerfile.node", "vite.config.docker.ts", "vite-service.yml", "vite-server-config.js"]
adapters: [claude, cursor, fable]
sha256: ""
---

Vite dev server = separate compose service next to Laravel app. Runs from same PHP image (if Node+pnpm installed, see `docker-php`) or separate node image.

## Rules

- Command: `pnpm install && pnpm run dev --host=0.0.0.0 --port=${VITE_PORT:-5173}` — install on every start (node_modules in volume); `--host=0.0.0.0` mandatory (else dev-server listens only on container loopback).
- node_modules = named volume, NOT bind-mount: `vite_node_modules:/var/www/html/node_modules` over the sources bind-mount.
- HMR: in-container `server.host: "0.0.0.0"`, browser hits from host → `hmr.host: "localhost"`. Port symmetric (`5173:5173`), `strictPort: true`. CORS = app `APP_URL`.

## Steps

1. Add `vite` service to dev-compose per `vite-service.yml` (same Dockerfile as app, or `Dockerfile.node`).
2. Declare named volume `vite_node_modules` in compose `volumes:`.
3. Map port `${VITE_PORT:-5173}:${VITE_PORT:-5173}`; reuse env-block via anchor `*app_env`.
4. Configure `vite.config.js` server block per `vite-server-config.js` (host/hmr/cors, port from `VITE_PORT`).
5. `depends_on: app: { condition: service_healthy }`.
6. WSL2/Linux Permission denied → local override `user: "1000:1000"` (see `docker-dev-prod`).

## Snippet map

| Situation | File |
|---|---|
| vite service in docker-compose | `snippets/vite-service.yml` |
| vite.config.js server block (host/HMR/CORS/port) | `snippets/vite-server-config.js` |
| Separate node image for frontend | `snippets/Dockerfile.node` |
| Minimal Docker vite config (TS, base) | `snippets/vite.config.docker.ts` |

## Quality checklist

- [ ] `--host=0.0.0.0` in dev-server command
- [ ] node_modules in named volume, not bind-mount
- [ ] `hmr.host: "localhost"`
- [ ] port parametrized `${VITE_PORT:-5173}`, symmetric mapping, `strictPort: true`
- [ ] CORS limited to app origin (`APP_URL`), not `*`
- [ ] vite depends on healthy app (`depends_on … service_healthy`)

## Links

- https://vite.dev/config/server-options — server.host / server.hmr
- https://laravel.com/docs/vite — laravel-vite-plugin
- Related: `docker-php` (Node in PHP image), `docker-dev-prod` (WSL2 override), `frontend-vite/vite-module-loader` (base Vite config/aliases), `frontend-vite/vite-multi-build` (multiple Vite builds in one project), `devops/node-pnpm-preflight` (Node/pnpm on host)

<!-- ru-source-sha256: 13c3ee965754dc74801b0750815fc79dd1a66cdec39feb3d528c48d797d5875b -->
