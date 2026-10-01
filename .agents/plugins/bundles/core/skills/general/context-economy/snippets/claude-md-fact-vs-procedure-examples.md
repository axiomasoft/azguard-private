# CLAUDE.md: fact vs procedure - test and examples

One question per section: **is this necessary? Claude in EVERY session, just to be clear
project, or only when a specific task is being done X?** Every session → fact → CLAUDE.md.
Only for X → procedure → skill (`.claude/skills/<name>/SKILL.md`, is loading lazily
`description`, non-resident).

Second, equivalent question: **this is declarative (what IS) or procedurally (what to DO)?**
Declarative - fact. Procedural (numbered steps, prompt templates, launch checklists) —
skill.

**Important Disclaimer:** `@import` in CLAUDE.md DOES NOT allow lazy loading - imported files
are expanded entirely at the start of the session (see main SKILL.md, section about myths). Real
savings - not in organizing the text through `@import`, and in the transfer of procedural content to the skill
(are only resident there `name`+`description`, body - by trigger).

## Example 1 - task prompt templates → skill

**Bad** (in CLAUDE.md, resident every session):

```markdown
## Task templates

### Code review
Review the last diff for: layer boundaries, impact on pipeline stages 1-4,
consistency with docs/workflow.md. List only blocking issues with file/line.

### Test generation
Add minimal tests for the changed behavior: one happy path, one negative case.
Run: php artisan test --compact --filter=...
```

**Okay** — put in `.claude/skills/task-templates/SKILL.md` (is loading lazily
description "Canned prompts for code review / test generation / ..."), in CLAUDE.md — nothing
(skill will connect itself according to the task) or a single pointer line if this is not self-evident.

## Example 2 - domain runbook (pipeline/steps/commands) → skill

**Bad** (120 lines: stage table, dependency map, test commands, PR-checklist - everything
is resident even when the task does not touch this pipeline):

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

**Okay** — entire section in `.claude/skills/parsing-pipeline-dev/SKILL.md` with
`paths: "app/Services/Parsing/**"` (or by trigger description), in CLAUDE.md maximum remains
one line per reference-table: «working with the parsing pipeline → skill `parsing-pipeline-dev`».

## Example 3 - Domain Glossary → cut down, remove details

**Bad** (80 lines: full canon of entities, verbs, test naming - resident, although
is only needed for naming/review):

```markdown
## Glossary
- Platform → $platform — source site
- CrawlerRun → $run — one crawl pass (not bare Run/Crawl)
... (60 more lines: verbs, test naming, exceptions)
```

**Okay** — leave only what you really need to know EVERY session without a domain (for example,
table «short table prefix ↔ fully qualified domain name» — this is a fact about the database structure), a
detailed entity canon/verbs/naming - in `docs/glossary.md` (reference) or in skill,
triggered by naming/review.

## What remains a fact (do not touch)

- Domain Map/layers («domain X → folder `app/Models/X/`») — fact about the code structure, needed
  every session to route edits.
- Non-standard build commands/test if they are not guessed by the framework.
- Explicit prohibitions («do not hardcode the table name in the migration - use `getTable()`»).
- Reference-table «situation → where to read» (it itself is a router, see main SKILL.md).
