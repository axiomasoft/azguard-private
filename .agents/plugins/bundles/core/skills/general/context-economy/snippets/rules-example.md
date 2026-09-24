# Шаблон path-scoped правила — .claude/rules/<topic>.md

Скопируйте в `.claude/rules/` проекта (например `.claude/rules/filament.md`).
Правило загрузится в контекст только когда Claude читает файлы, совпадающие с glob.

```markdown
---
paths:
  - "app/Filament/**"
  - "tests/Feature/Filament/**"
---

# Filament

- Scaffold только через `php artisan make:filament-*`, не вручную.
- Actions импортировать из `Filament\Actions\*` (не `Filament\Tables\Actions`).
- Labels/headings — на языке проекта.
```

Глоб-паттерны: `**/*.ts` (все .ts), `src/**/*` (всё под src/), `src/**/*.{ts,tsx}` (brace expansion).
Правило БЕЗ `paths:` грузится каждую сессию — как второй CLAUDE.md. Используйте это
сознательно только для правил, нужных всегда.

Правила можно шарить между проектами симлинками:

```bash
ln -s ~/shared-claude-rules .claude/rules/shared
```
