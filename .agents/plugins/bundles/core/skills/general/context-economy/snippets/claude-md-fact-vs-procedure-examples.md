# CLAUDE.md: факт vs процедура — тест и примеры

Один вопрос на каждый раздел: **нужно ли это Claude в КАЖДОЙ сессии, просто чтобы понимать
проект, или только когда делается конкретная задача X?** Каждую сессию → факт → CLAUDE.md.
Только для X → процедура → скилл (`.claude/skills/<name>/SKILL.md`, лениво грузится по
`description`, не резидентно).

Второй, эквивалентный вопрос: **это декларативно (что ЕСТЬ) или процедурно (что ДЕЛАТЬ)?**
Декларативное — факт. Процедурное (нумерованные шаги, шаблоны промптов, чеклисты запуска) —
скилл.

**Важная оговорка:** `@import` в CLAUDE.md НЕ даёт ленивую загрузку — импортированные файлы
разворачиваются целиком при старте сессии (см. основной SKILL.md, раздел про мифы). Реальная
экономия — не в организации текста через `@import`, а в переносе процедурного контента в скилл
(там резидентны только `name`+`description`, тело — по триггеру).

## Пример 1 — шаблоны промптов задач → скилл

**Плохо** (в CLAUDE.md, резидентно каждую сессию):

```markdown
## Task templates

### Code review
Review the last diff for: layer boundaries, impact on pipeline stages 1-4,
consistency with docs/workflow.md. List only blocking issues with file/line.

### Test generation
Add minimal tests for the changed behavior: one happy path, one negative case.
Run: php artisan test --compact --filter=...
```

**Хорошо** — вынести в `.claude/skills/task-templates/SKILL.md` (лениво грузится по
description "Canned prompts for code review / test generation / ..."), в CLAUDE.md — ничего
(скилл подключится сам по задаче) либо одна строка-указатель, если это не самоочевидно.

## Пример 2 — доменный runbook (пайплайн/шаги/команды) → скилл

**Плохо** (120 строк: таблица стадий, dependency map, тестовые команды, PR-чеклист — всё
резидентно, даже когда задача не касается этого пайплайна):

```markdown
## Parsing pipeline
| Stage | What | Code |
|---|---|---|
| 1 Collector | fetch listings | Services/Collector.php |
...
## Dependency map
1. Migrations — database/migrations/
2. Enums/DTO — ...
## Test gate
make test-preflight && make test-php
```

**Хорошо** — весь раздел в `.claude/skills/parsing-pipeline-dev/SKILL.md` с
`paths: "app/Services/Parsing/**"` (или по триггеру description), в CLAUDE.md остаётся максимум
одна строка в reference-таблице: «работа с пайплайном парсинга → скилл `parsing-pipeline-dev`».

## Пример 3 — доменный глоссарий → сократить, детали унести

**Плохо** (80 строк: полный канон сущностей, глаголов, тестового нейминга — резидентно, хотя
нужно только при именовании/ревью):

```markdown
## Glossary
- Platform → $platform — source site
- CrawlerRun → $run — one crawl pass (not bare Run/Crawl)
... (60 more lines: verbs, test naming, exceptions)
```

**Хорошо** — оставить только то, что реально нужно знать КАЖДУЮ сессию без домена (например,
таблица «короткий префикс таблицы ↔ полное доменное имя» — это факт про структуру БД), а
подробный канон сущностей/глаголов/нейминга — в `docs/glossary.md` (референс) или в скилл,
триггерящийся на именование/ревью.

## Что остаётся фактом (не трогать)

- Карта доменов/слоёв («домен X → папка `app/Models/X/`») — факт о структуре кода, нужен
  каждую сессию для маршрутизации правок.
- Нестандартные команды сборки/теста, если они не угадываются по фреймворку.
- Явные запреты («не хардкодь имя таблицы в миграции — используй `getTable()`»).
- Reference-таблица «ситуация → куда читать» (сама она и есть роутер, см. основной SKILL.md).
