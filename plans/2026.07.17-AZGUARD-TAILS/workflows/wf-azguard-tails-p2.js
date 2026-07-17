export const meta = {
  name: 'azguard-tails-p2',
  description: 'AzGuard 2026.07.17-AZGUARD-TAILS — P2: T3/T4/T5 (independent scope, deterministic validation)',
  phases: [
    { title: 'Implement', detail: 'P2.1 (T3), P2.2 (T4), P2.3 (T5) — sequential, same working tree' },
  ],
}

// P2 declares orchestration per plan-protocol §7 (D4, plan.md): 3 items with
// independent scope + deterministic Validation. Items still close ONE AT A TIME
// against the SAME working tree (repo, not per-item worktrees) — sequential, not
// parallel, to keep the plan-protocol two-commit close topology (item commit +
// bookkeeping commit, §8) uncontended. This script removes the manual
// confirmation ritual between P2.1→P2.2→P2.3, not the git discipline itself.

const REPO = '/home/vostrikov/projects/packages/azguard'
const PLAN = 'plans/2026.07.17-AZGUARD-TAILS'

function itemPrompt(itemId, summary) {
  return `Repo: ${REPO}. Working directory is this repo.

Execute item ${itemId} of the AzGuard master plan ${PLAN}/plan.md (protocol: skill
\`plan-protocol\`, general). Read, in this order, BEFORE writing any code:
1) ${PLAN}/plan.md (Meta, Context, Routing — confirm ${itemId} is Exec=plan-exec, sonnet/medium)
2) ${PLAN}/phases/P2.md — the full ${itemId} spec (all 16 fields: Intent, Why, Scope
   Included/Excluded, Inputs, Files, Required Reads, Implementation Rules, Code
   Guidance, Validation, Deliverables). Code Guidance is the binding spec — follow it
   exactly, do not improvise a different approach.
3) Every file listed under ${itemId}'s "Required Reads" in phases/P2.md, in the given
   order, before editing anything.

Summary of ${itemId}: ${summary}

Close the item per plan-protocol §8 "Item close order" (read that section's rules if
available via the plan-protocol skill; otherwise follow this condensed version):
1. Implement per Code Guidance. Run the item's Validation commands (in phases/P2.md)
   until green — full command list, not a subset.
2. Item commit: \`git add\` ONLY the paths under this item's "Files" (never \`git add -A\`
   or \`git commit -a\` — a foreign diff in the tree is not yours to sweep in). Conventional
   Commits, Russian, message references the item ID (e.g. \`fix(core): P2.1 — <суть>\`).
3. Update phases/P2.md: this item's Status → 🟢 (or 🟠 if a material deviation occurred —
   see plan-protocol §6 for what counts as material), fill Completion Notes / Pending
   Work / Known Deviations, update the Phase Status table row.
4. Update plan.md: Phase Index & Status Board row for P2 (items 🟢/total), Update Log
   row (format: "<item> закрыт: <1 line> — детали см. phases/P2.md <item> Completion
   Notes"), Last Updated.
5. Bookkeeping commit: \`git add ${PLAN}\` only, Conventional Commits, e.g.
   \`docs(plan): P2.1 закрыт — bookkeeping\`.

Do NOT touch plans/ACTIVE.md (untouched by item closes — only plan-close/new touch it).
Do NOT edit other items (P2.2/P2.3/P1.*) in this run. If Code Guidance's "Escalation
Needed" is anything other than "no", or you hit a plan-protocol §10 escalation trigger
(rule conflict / unclear acceptance / public contract) that Code Guidance did not already
resolve for you, STOP: do not improvise past it. Leave the item's Status at 🔴 Blocked,
write the three escalation artifacts prescribed by plan-protocol §10, and end your report
with the line \`ESCALATION-REQUIRED: ${itemId} — <причина>\`.

Report back: final Validation command outputs (pass/fail), the item commit hash, the
bookkeeping commit hash, and any deviation from Code Guidance with justification.`
}

phase('Implement')

const p21 = await agent(
  itemPrompt(
    'P2.1',
    'T3 — EnumPermissionCatalogBuilder::build() silently `continue`s on a missing enum ' +
    'class; PolicyAbilityCatalogBuilder logs a warning in the equivalent branch. Add the ' +
    'same Log::warning (mirror its exact message format) + a test proving the call.',
  ),
  { label: 'P2.1 (T3)', phase: 'Implement', model: 'sonnet', effort: 'medium' },
)

const p22 = await agent(
  itemPrompt(
    'P2.2',
    'T4 — EffectivePermissionResolver::filterAgainstCatalog(), wildcard-OFF branch, does ' +
    'not exclude keys containing PermissionKey::WILDCARD before checking them against ' +
    'dynamic catalog definitions — a literal "*" can accidentally match a "{seg}" ' +
    'placeholder. Mirror the wildcard-ON branch\'s existing `str_contains($key, WILDCARD)` ' +
    'guard into the wildcard-OFF branch, plus a positive+negative regression test.',
  ),
  { label: 'P2.2 (T4)', phase: 'Implement', model: 'sonnet', effort: 'medium' },
)

const p23 = await agent(
  itemPrompt(
    'P2.3',
    'T5 — migration 2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php ' +
    'down() is documented (in its own docblock) to fail on MySQL/PostgreSQL when a ' +
    'null scope_class row exists, but no rollback test proves it. Add a Feature test ' +
    'that EXPERIMENTALLY establishes the actual behavior on this project\'s SQLite test ' +
    'DB (do not assume the docblock\'s MySQL/PostgreSQL claim transfers) — do NOT change ' +
    'down() itself (that is an explicit Escalation Needed: yes if the test proves ' +
    'unwritable without it — do not decide data-loss semantics unilaterally).',
  ),
  { label: 'P2.3 (T5)', phase: 'Implement', model: 'sonnet', effort: 'medium' },
)

return { p21, p22, p23 }
