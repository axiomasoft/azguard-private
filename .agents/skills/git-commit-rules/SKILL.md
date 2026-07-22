---
name: git-commit-rules
bucket: general
version: 0.2.0
description: "Git workflow: commit prep, husky/commitlint, message format, safe action order."
risk: write
persona: oss-dev
tags: [git, conventions, conventional-commits, gitops]
requires: [node-pnpm-preflight]
produces_for: [github-flow]
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Git Commit Rules

Use when task involves:
- `git commit`, `git add`, `git status`, PR prep
- diagnosing git hook failures
- checking commit message format

## Mandatory order before commit

1. `git status --short`.
2. `git diff` (and `git diff --staged` if needed).
3. Apply Node/pnpm preflight before committing: `.ai/skills/node-pnpm-preflight/SKILL.md`.
4. Don't bypass hooks (`--no-verify` forbidden without explicit user request).
5. Run `git commit` only after local checks pass.

## Husky + Commitlint

- `commit-msg` hook is a mandatory check.
- If hook fails due to environment (`pnpm`/`node` unavailable): don't disable hook; first restore environment via `node-pnpm-preflight`; only then retry commit.

## Commit message format

- Use project's conventional style.
- Message in Russian.
- Type prefixes: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `style`, `build`, `ci`, `revert` (+ `security` if project uses it).
- Scope from project commitlint config (if present), else derive from project structure/layers; don't invent.
- Subject as "done", not "to do": past tense, usually plural — `добавили`, `исправили`, `убрали`, `обновили`; avoid infinitives `добавить`, `исправить`, `сделать`.
- Style: short subject + 1-2 lines with reason if needed.
- Read as human-written, not AI template: concrete result + why; no bureaucratese, no generic phrases.

Example:

```text
refactor(workflow): унифицировать кэш policy abilities

Убрал дубли в DTO и вынес общий helper для кэширования.
```

More "human-style" examples:

```text
ci(deps): добавили php-расширения для composer install

Добавили ldap/gd/exif в GitLab CI, чтобы composer install не падал на platform req.
```

```text
fix(api): исправили проверку роли в ответе тикета

Убрали ложный 403 для участников без роли исполнителя.
```

Anti-example:

```text
ci(deps): добавить php-расширения для composer install
```

## Conventional Commits: scopes and breaking changes

- Scope source of truth — project commitlint config. If none — derive scope from project structure/layers, don't invent.
- For PHP packages without config, reasonable default (example, not dogma): `middleware`, `provider`, `contract`, `config`, `command`, `policy`, `event`, `exception`, `rule`.
- Mark breaking change one of two ways: footer `BREAKING CHANGE: <what broke and how to migrate>`; or `!` after type/scope: `feat!: ...`, `feat(api)!: ...`.
- Type→SemVer bump interpretation lives in `oss-dev/github-flow` (SemVer Oracle), not duplicated here.

## Safety

- No destructive git commands without explicit user request.
- Don't mix unrelated changes in one commit.
- Don't manually edit generated AI artifacts (`CLAUDE.md`, `AGENTS.md`).

## Conformance

- Run `git status --short` and `git diff` before committing — know what you commit.
- Don't bypass hooks: `--no-verify` forbidden without explicit user request.
- Format — conventional: `type(scope): subject`; type from the project's list.
- Take scope from commitlint config or project structure, don't invent.
- Message in Russian; subject in past tense ("добавили"), not an infinitive.
- Body — 1–2 lines: concrete result + why, no bureaucratese.
- Mark breaking changes: `BREAKING CHANGE:` footer or `!` after type/scope.
- Don't mix unrelated changes in one commit.
- Destructive git commands only on explicit user request.

## Related skills

- `writing-style` (general) — tone/language of commit body (Russian, reason over mechanics); format — here.
- `devops/node-pnpm-preflight` — Node/pnpm check before commit with husky/commitlint hooks.
- `oss-dev/github-flow` — Issue → Branch → PR → Merge → Tag chain and SemVer Oracle (commit type → version bump).

<!-- ru-source-sha256: 4747a3144dbb40cf871cecc0757c62015ac8b17ed8a5dd106ffdfc2fe8255730 -->
