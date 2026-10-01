# Template path-scoped rules - .claude/rules/<topic>.md

Copy to `.claude/rules/` project (for example `.claude/rules/filament.md`).
The rule will be loaded into the context only when Claude reads files matching glob.

```markdown
---
paths:
  - "app/Filament/**"
  - "tests/Feature/Filament/**"
---

# Filament

- Scaffold only via `php artisan make:filament-*`, not manually.
- Actions import from `Filament\Actions\*` (not `Filament\Tables\Actions`).
- Labels/headings — in the project language.
```

Glob patterns: `**/*.ts` (all .ts), `src/**/*` (all under src/), `src/**/*.{ts,tsx}` (brace expansion).
Rule WITHOUT `paths:` is loaded every session - like the second CLAUDE.md. Use this
deliberately only for rules that are always needed.

Rules can be shared between projects using symlinks:

```bash
ln -s ~/shared-claude-rules .claude/rules/shared
```
