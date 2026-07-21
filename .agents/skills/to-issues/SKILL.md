---
name: to-issues
bucket: oss-dev
version: 0.1.0
description: "Split a plan/spec/PRD into independently-grabbable GitHub issues by VERTICAL slices (tracer bullets): each slice is a thin end-to-end path through all layers (schema/API/UI/tests), demonstrable on its own; publish via gh in dependency order (blockers first). Invoke EXPLICITLY (posts to the tracker)."
risk: write
persona: oss-dev
tags: [issues, github, vertical-slices, decomposition, planning]
requires: [github-flow]
produces_for: [implement]
outputs: []
snippets: []
adapters: [claude, cursor, fable]
disable-model-invocation: true
sha256: ""
---

# To Issues

Break a plan into independently-grabbable issues using vertical slices (tracer bullets).

> **Конфиг трекера.** Перед публикацией нужны: репозиторий-трекер и словарь triage-лейблов. У нас
> это `gh` + лейблы конкретного репо (а не `/setup-matt-pocock-skills`). Если набор лейблов неясен —
> уточни у мейнтейнера или возьми из `oss-dev/github-flow`.

## Process

### 1. Gather context

Work from whatever is already in the conversation context. If the user passes an issue reference (issue number, URL, or path) as an argument, fetch it from the issue tracker and read its full body and comments.

### 2. Explore the codebase (optional)

If you have not already explored the codebase, do so to understand the current state of the code. Issue titles and descriptions should use the project's domain glossary vocabulary (`CONTEXT.md`), and respect ADRs in the area you're touching.

Look for opportunities to prefactor the code to make the implementation easier. "Make the change easy, then make the easy change."

### 3. Draft vertical slices

Break the plan into **tracer bullet** issues. Each issue is a thin vertical slice that cuts through ALL integration layers end-to-end, NOT a horizontal slice of one layer.

<vertical-slice-rules>

- Each slice delivers a narrow but COMPLETE path through every layer (schema, API, UI, tests)
- A completed slice is demoable or verifiable on its own
- Any prefactoring should be done first

</vertical-slice-rules>

### 4. Quiz the user

Present the proposed breakdown as a numbered list. For each slice, show:

- **Title**: short descriptive name
- **Blocked by**: which other slices (if any) must complete first
- **User stories covered**: which user stories this addresses (if the source material has them)

Ask the user:

- Does the granularity feel right? (too coarse / too fine)
- Are the dependency relationships correct?
- Should any slices be merged or split further?

Iterate until the user approves the breakdown.

### 5. Publish the issues to the issue tracker

For each approved slice, publish a new issue to the issue tracker (via `gh issue create`). Use the issue body template below. These issues are considered ready for AFK agents, so publish them with the correct triage label unless instructed otherwise.

Publish issues in dependency order (blockers first) so you can reference real issue identifiers in the "Blocked by" field.

<issue-template>
## Parent

A reference to the parent issue on the issue tracker (if the source was an existing issue, otherwise omit this section).

## What to build

A concise description of this vertical slice. Describe the end-to-end behavior, not layer-by-layer implementation.

Avoid specific file paths or code snippets — they go stale fast. Exception: if a prototype produced a snippet that encodes a decision more precisely than prose can (state machine, reducer, schema, type shape), inline it here and note briefly that it came from a prototype. Trim to the decision-rich parts — not a working demo, just the important bits.

## Acceptance criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Affected projects

Список затронутых репозиториев, если срез касается нескольких проектов экосистемы (иначе опусти секцию).

## Environment

Где проявляется/проверяется: production / staging / конкретное устройство (POS, фискальник). Опусти, если неприменимо.

## Blocked by

- A reference to the blocking ticket (if any)

Or "None - can start immediately" if no blockers.

</issue-template>

Do NOT close or modify any parent issue.

## Связанные скиллы

- `oss-dev/github-flow` — общий поток Issue→PR→Release (обязательная зависимость).
- `general/spec-interview` / `general/grill-with-docs` — откуда берётся план/ТЗ для разбиения.
- `general/implement` — следующий шаг: реализация по опубликованным issue (этот скилл `produces_for` его).
- `oss-dev/issue-triage` — стейт-машина обработки уже существующих issue/PR.
