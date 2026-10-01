# Root template CLAUDE.md

Skeleton for a new project or for re-partitioning an existing bloated file. Sections -
fixed structure; text inside `[FILL: ...]` — project freedom (write as it is, briefly).
Each section has already passed the fact testvs-procedure (see main SKILL.md) — if for your project
section draws on the procedure/checklist/prompt template, DO NOT write it here: create a skill/command
(`.claude/skills/<name>/SKILL.md` or `.claude/commands/<name>.md`) and leave in this section only
link to it. Managed-skill hub block (markers `swissknifeman:hub:start/end`) — NOT part of this
template, it writes `generate-hub.sh`/`skiller sync` itself, do not touch it with your hands.

```markdown
# CLAUDE.md — [FILL: project name]

[FILL: one line - what kind of project is this/domain]. [FILL: stack: language+version, framework+version, database,
queues/cache, key external services].

[FILL, if applicable: where is the source of truth AI-context, if not the file itself - e.g. .ai/guidelines/*
+ rule "edit there, not here"; if there is no auto-composition - one line about it].

## Domain map (or "Structure" for non-domain projects)

[FILL: 3-7 items - domain modules/bounded context's and their short prefixes/folders IF they
is; otherwise, it’s just a map of top-level directories and what responsibilities each has].
Full structure by layers (Models/Services/...) — [FILL: link to docs/, if it exists and in more detail;
otherwise leave here, but do not duplicate elsewhere].

## Naming canon (short)

[FILL: only what is really non-standard/project specific - 3-6 lines: domain
entity→variable if it is not obvious from the code; verbs of methods, if different from the general
skill `naming-conventions`]. Full glossary - [FILL: link, if there is a separate docs-file].

## Operating policies (bans, non-default solutions)

[FILL: 3-6 points - that "will surprise an experienced developer new to the repo": non-standard solutions
against framework default, strict prohibitions, specifics Docker/runtime]. DO NOT write here that the model
already knows from training (standard patterns of the framework) or aspirational-tips ("write clean
code").

## Where to get parts (router)

| Situation | File/skill |
|---|---|
| [FILL: typical project situations → docs/ or skill `name`] | |
| Tests | skill `<test-skill>` |
| Commits | skill `git-commit-rules` |
| Complex multi-step task | skill `complex-task-orchestrator` |

<!-- below - managed-skill hub block, writes generate-hub.sh, do not edit manually -->
```

## How to use

- **New project**: copy skeleton, fill `[FILL: ...]`, remove sections that are for
  project are not needed (better empty than tense filling).
- **Existing bloated CLAUDE.md**: drive away `snippets/claude-md-checklist.md` for each
  section of the file → for each solve the fact (remains, condensed to the essence) / procedure (put in the skill,
  leave the line in router-table) / duplicate with docs (replace with link) / deprecated (check
  according to the current code before transferring, do not copy blindly - see.
  `snippets/claude-md-fact-vs-procedure-examples.md`).
- The result is always less than the original, but not at any cost: if after an honest run of the checklist it is a fact
  remains a fact - it remains, even if it means not fitting into 200 lines. Bloating is a signal
  search for procedures/duplicates, and not a reason to cut facts arbitrarily.
