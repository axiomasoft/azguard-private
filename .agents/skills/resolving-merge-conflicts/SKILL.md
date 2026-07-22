---
name: resolving-merge-conflicts
bucket: oss-dev
version: 0.1.0
description: "Discipline for resolving an active git merge/rebase conflict: understand the state and the primary source of each change (commits/PRs/issues), resolve each hunk preserving both intents where possible (don't invent behavior, never --abort), run project checks, finish the merge/rebase. Activate on a merge/rebase conflict."
risk: write
persona: oss-dev
tags: [git, merge, rebase, conflicts]
requires: [git-commit-rules]
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
disable-model-invocation: false
sha256: ""
---

# Resolving Merge Conflicts

1. **See the current state** of the merge/rebase. Check git history, and the conflicting files.

2. **Find the primary sources** for each conflict. Understand deeply why each change was made, and what the original intent was. Read the commit messages, check the PRs, check original issues/tickets.

3. **Resolve each hunk.** Preserve both intents where possible. Where incompatible, pick the one matching the merge's stated goal and note the trade-off. Do **not** invent new behaviour. Always resolve; never `--abort`.

4. Discover the project's **automated checks** and run them — typically typecheck, then tests, then format. Fix anything the merge broke. (Наш стек: `php artisan test` / `phpstan`; `dart analyze` + `flutter test`; `vitest` / `vue-tsc`.)

5. **Finish the merge/rebase.** Stage everything and commit. If rebasing, continue the rebase process until all commits are rebased.

## Связанные скиллы

- `general/git-commit-rules` — формат итогового merge/rebase-коммита (обязательная зависимость).
- `oss-dev/github-flow` — общий поток веток/PR, в котором возникает конфликт.
