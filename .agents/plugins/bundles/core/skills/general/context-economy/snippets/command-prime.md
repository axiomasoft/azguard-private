# Command template /prime — copy to .claude/commands/prime.md project

```markdown
---
description: Loading the starting context of the session - the minimum sufficient for work
model: haiku
---

Prepare the working context of the session:

1. Read CLAUDE.md and print a one-line summary of the project rules.
2. Execute `git status` and `git log --oneline -5` — current state of the branch.
3. If in `.claude/plans/` there is an unfinished plan - name it, but DO NOT read the whole thing.
4. Ask what we're working on. Don't proactively read anything else.
```
