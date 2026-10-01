# Audit checklist CLAUDE.md

Run whenever the file grows noticeably. Landmark Anthropic: **≤200 lines**.

## Size

| Lines | Rating |
|---|---|
| >500 | critically many - success rate falls, cost +20% |
| 200–500 | needed trim |
| <200 | official landmark |
| <100 | target |

## For each line

- [ ] Would this surprise an experienced developer new to the repo? No → delete.
- [ ] The model knows this from training (standard framework/language)? Yes → delete.
- [ ] This aspirational («write clean code», «be careful»)? → delete.
- [ ] These are the contacts/schedules/FAQ without action? → delete.
- [ ] Is this a multi-step procedure? → put in skill.
- [ ] Is this only needed for part of the codebase? → put in `.claude/rules/<topic>.md` with `paths:`.
- [ ] Is this a note for the people, not the agent? → wrap in `<!-- ... -->` (is cut before injection).

## Anti-patterns

- `@import` for the sake of «savings» — imported files are loaded entirely at startup;
  is the organization of the text, it does not save tokens.
- Two rules contradict each other - the model will choose arbitrarily; resolve the conflict.
- Duplicates between CLAUDE.md, nested CLAUDE.md and `.claude/rules/` — leave one space.
