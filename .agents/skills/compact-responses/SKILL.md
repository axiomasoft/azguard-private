---
name: compact-responses
bucket: general
version: 0.2.1
description: "Activate ultra-compact response mode: minimal prose, code-only, no explanations unless asked. Use for terse output — quick edits, code reviews, repeated tasks with established context."
risk: read
persona: oss-dev
tags: [conventions, style, tokens, context, workflow]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Compact Response Mode

When active:

- Code tasks: show only changed code. No explanation of what was done.
- Questions: one sentence max. No examples unless asked.
- Errors/bugs: show the fix; optionally one-line reason if non-obvious.
- No trailing summaries — don't recap changes.
- No "I'll now..." preambles — just do it.

## Development loop

- Intermediate steps: don't narrate; output only final result.
- Tests passed: `✓ tests passed (N)` — never full runner output.
- Tests failed: only failing test name + error message, nothing else.
- Code changes: only changed fragments, never whole file.
- Don't echo the task back in any form.
- Banned phrases: "Я понял", "Сейчас сделаю", "Готово, вот результат" and equivalents.

## Structured reports (reviews/findings) — compact

- Findings/review: **one line per item** — `[SEV] file:line — issue. fix: …`; no repeated section headers, no prose beyond `failure_scenario`.
- Verdict / gates / "not verified": compact `key: val`, not paragraphs.
- Don't reorder or restate what was already said in prior turns.

## Final task report — only verbose moment (required)

```text
Сделано: <список>
Не сделано и почему: <если есть>
Изменённые файлы: <пути>
Проверка: <команды>
```

Mode stays active rest of conversation unless user says "normal mode" or "подробнее".

## Links

- `context-economy` (general) — session **context** economy (CLAUDE.md, rules, /compact, MCP-audit); this skill = **output** terseness, no overlap.
- `pao` (php) — compress PHP-tool output (tests/static-analysis) to compact JSON.
- `writing-style` (general) — tone/language of artifacts when verbose text is needed.
- Output-style `lean` (harness) — session-wide enforcement of the same voice at system-prompt level; this skill = portable SSOT of the phrasings (the output-style distills it).

<!-- ru-source-sha256: f99e7994b55f460553f0bfe9330f19be7e96479398cb70987458ce32faa930ae -->
