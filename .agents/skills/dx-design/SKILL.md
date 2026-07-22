---
name: dx-design
bucket: oss-dev
version: 0.1.0
description: "Developer Experience: README, quick-start (≤60s), API ergonomics, error messages, types, CLI UX"
risk: draft
persona: oss-dev
tags: [oss, documentation, api, cli]
requires: [oss-development]
produces_for: []
outputs: ["ProjectName/DX_Design.md", "ProjectName/README.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Developer Experience Design

Apply when: OSS project is **preparing to publish** OR gets **"hard to get started" feedback**. Must run **after `oss-development`**. Often paired with `release-engineering` + `oss-governance`.

## When NOT to apply
- Internal-only package, no external consumers — minimal README suffices.
- Already mature README + examples + types — refuse, don't redo.
- Package doesn't yet do what it promises — function first, DX later. Polish nothing.

## 6 DX axes

### 1. Time-to-First-Success (TTFS)
Goal: new user gets a **working result in ≤60s** from landing on README. Time each step:
- Read first README paragraph → understand what it is
- Find install command → run
- Find minimal example → copy → run
- See success

**Any step >15s = DX problem.** Typical causes: README long, install at bottom; minimal example needs a config file; API key / env var needed but no source explained.

### 2. README structure (mandatory)
Section order (do NOT change):

```markdown
# ProjectName

> One line: what it is and for whom. **No marketing.**

[![ci](badge)] [![npm](badge)] [![license](badge)]

## Install
```bash
npm install [name]
```

## Quick example
```ts
import { Brain } from '[name]';
const b = new Brain({ apiKey: '...' });
const r = await b.query('hello');
console.log(r);
```

## When to use
- Use case 1
- Use case 2

## When NOT to use
- Anti-case 1
- Anti-case 2

## Features
[5–8 bullets]

## Documentation
→ [full docs](link)

## License

License choice/application (MIT/Apache-2.0/BSD/…), differences and when — [references/license-guidance.md](references/license-guidance.md).
```

Agent adds itself:
- **DX metrics.** Measure TTFS on a clean VM. If package has npm stats — track `time-from-install-to-first-import` via telemetry (opt-in).
- **Public dogfooding.** Maintainer must use own package in a non-trivial project; else DX bugs invisible.
- **Issue templates.** `bug_report.yml`, `feature_request.yml`, `question.yml` — reduce noise, structured bugs. (Overlaps `oss-governance`.)
- **Performance baseline.** README one figure: «cold start: X ms, query: Y ms». No deceptive competitor benchmarks; **own** baseline honestly.
- **TypeScript: source of truth.** For JS packages types = public API. Any type change = breaking. See `release-engineering`.

## Output files

### `ProjectName/DX_Design.md`
```markdown
---
project: [ProjectName]
type: oss-process
based_on: oss-development.md
---

# DX Design — [ProjectName]

## TTFS goal
- Current TTFS on clean VM: X s
- Target: ≤ 60 s

## README structure
- [accepted section order]

## API ergonomics
- Main entry-point: ...
- Defaults: ...
- Error class: ...
- Type-safety: ...

## Error message contract
- Template: [What] / Why / Fix / Docs
- Error codes catalog: [...]

## CLI UX (if any)
- Help: ...
- Color/TTY: ...
- Exit codes: ...

## Documentation
- Structure: ...
- Tooling: TypeDoc / phpDocumentor / dartdoc
- Hosting: GitHub Pages / docs/ in-repo

## DX metrics & checks
- [ ] README ≤ 200 lines
- [ ] Quick example works by copy-paste
- [ ] All error messages have docs URL
- [ ] CLI `--help` is informative
- [ ] `npm install && import` works without config
```

### `ProjectName/README.md`
Created/rewritten per structure above. Primary DX artifact.

## References
- `oss-dev/oss-development` — prerequisite: base structure + understanding what package does.
- `oss-dev/release-engineering` — DX metrics in release notes; TS types as public API (breaking).
- `oss-dev/oss-governance` — CONTRIBUTING/CoC + issue/PR templates — also DX.
- `php-packages/laravel-package-docs` — README/VitePress/usage docs for PHP packages (language-specific).
- `general/writing-style` — tone/language of README, error messages, docs.

## Hard prohibitions
FORBIDDEN:
- Publish `v1.0+` without README in the structure above
- Error messages without a `Fix:` hint
- Public functions API with `any` types
- Long README (>300 lines) — move to docs/
- Marketing text in README («revolutionary», «AI-powered» without substance)
- Quick example requiring a config file
- Hiding install behind a «requirements» section

<!-- ru-source-sha256: dd919ed4e8fa452853e01603cab9054c43cc2b42484205a11960412aa56a5683 -->
