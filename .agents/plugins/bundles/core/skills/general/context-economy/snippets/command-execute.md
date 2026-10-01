# Command template /execute — copy to .claude/commands/execute.md project

```markdown
---
description: Executing a ready-made plan from a file (second pattern session Plan→Clear→Execute)
model: sonnet
argument-hint: <path to plan from .claude/plans/>
---

Execute the plan: $ARGUMENTS

1. Read the plan file. This is the only source of task context −
   do not try to restore the discussion history.
2. Follow the steps in order; read only files named in the plan
   (plus what they explicitly require).
3. If the plan conflicts with the actual code, stop and describe the discrepancy,
   don't improvise.
4. Finish with verification from the plan and a compact final report:
   done / not done and why / modified files / verification commands.
```
