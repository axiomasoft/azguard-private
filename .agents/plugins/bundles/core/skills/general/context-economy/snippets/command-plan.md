# Command template /plan — copy to .claude/commands/plan.md project

```markdown
---
description: Task plan to file - without execution (first pattern session Plan→Clear→Execute)
model: opus
argument-hint: <task description>
---

Task: $ARGUMENTS

Create an implementation plan WITHOUT execution:

1. Examine the affected code (read only).
2. Write the plan in `.claude/plans/<kebab-task-name>.md`:
   - Context: why and what we are changing;
   - Steps with specific files;
   - Invariants (that cannot be broken);
   - Verification: verification commands.
3. The plan must be self-contained: it will be executed in a NEW session,
   without access to the current discussion.
4. Print the path to the plan file and stop. Don't start implementation.

After this: `/clear`, then `/execute <path to plan>`.
```
