---
name: node-pnpm-preflight
bucket: devops
version: 0.1.0
description: "Check Node/pnpm availability on host before commands and git hooks that use pnpm (commitlint, lint, build)."
risk: read
persona: operator
tags: [node, pnpm, devops, preflight, ci]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Node/pnpm Preflight

Run before any host command needing `pnpm` or `node`:
- git hooks (`commit-msg`, `pre-commit`, `commitlint`)
- frontend (`pnpm lint`, `pnpm test`, `pnpm build`)
- any local `package.json` script

## Check order
1. `pnpm --version`.
2. If `pnpm` missing: `nvm use 22`.
3. Re-check `pnpm --version`.
4. If `pnpm` still unavailable after `nvm use 22` — stop and report blocker. Do NOT skip hooks.

## Commits
Before `git commit`, ensure `pnpm` and `node` available in current shell session, else `commit-msg` hook with `commitlint` fails.

## Related
- `devops/db-test-preflight` (analogous preflight for test DB)
- `devops/docker-vite` (Vite/pnpm in Docker service)
- `general/git-commit-rules` (husky/commitlint — the reason pnpm is needed in shell)

<!-- ru-source-sha256: da30f1c895ef48ad3868e4c59d81eb908e57dd1bff031f98263219fa5faf9fa6 -->
