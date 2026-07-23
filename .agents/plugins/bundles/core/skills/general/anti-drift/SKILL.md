---
name: anti-drift
bucket: general
version: 0.4.0
description: "Discipline against agent rabbit-holing: think before coding, simplicity first, surgical edits, circuit-breaker on dragging iterations."
risk: read
persona: oss-dev
tags: [workflow, discipline, orchestration, context]
requires: []
produces_for: [session-handoff]
outputs: []
snippets: ["claude-md-anti-drift.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Anti-Drift

## When to activate

Any code-writing/editing session, especially when:
- task already took >2–3 "try another way" iterations;
- solution accretes new layers/flags/dependencies;
- session is long, early decisions risk being forgotten.

## Four principles (Karpathy)

1. **Think Before Coding** — before editing state: what changes, why, which assumptions. On ambiguity ask ONE clarifying question, don't guess. Check: can you explain the plan in one paragraph? If not, plan isn't ready.
2. **Simplicity First** — minimal code for the current task; not one line "for the future" (YAGNI). Check: "could I write this simpler?" If yes, rewrite before committing.
3. **Surgical Changes** — touch only files named in the task; don't improve neighboring code unrequested. Check: `git diff` — any out-of-scope change? Revert it.
4. **Goal-Driven Execution** — success criterion (test/check) first, then code. Check: is there a check that fails BEFORE and passes AFTER?

## File-op hygiene (read/edit/write)

Three concrete rules against file-op churn — a measured (not hypothetical) source of wasted
iterations: reread dwarfs Edit/Write failures by an order of magnitude, and failures are the
tool's existing gate firing (Edit/Write rejects editing a file not Read THIS session), not a
silent overwrite.

1. **Read-before-edit.** Before Edit/Write a file, confirm it was Read THIS session (not from
   memory of an earlier iteration). The tool rejects the attempt anyway without a read — a
   blind attempt is pure churn (reject → forced-read → retry) with no upside. Check: can you
   cite the line number of the last Read of this file in the current session?
2. **Anchor hygiene.** Take `old_string` verbatim from the FRESH Read output, not from
   memory/guess — large, unique context (several lines, not one word), else
   `string-not-found`/`not-unique`. Repeated edit pattern → `replace_all=true` instead of a
   series of single Edits. Check: is `old_string` copied from the latest Read output, not
   typed from memory?
3. **Anti-reread.** A file already visible in session context, unchanged since its last Read
   (by you or an external tool) — don't reread it, use the content already in context. Reread
   is the largest measured driver of file-op churn, yet produces NO tool error (not caught by
   failure categorization) — only discipline stops it. Check: any Edit/Write of THIS file
   (yours or someone else's) between now and its last Read? No → don't reread.

## Circuit Breaker — stop thresholds

Catches three degradation patterns: layered edits (new layer atop old instead of removing it), symptomatic fixes (treat the log not the cause), context saturation (conflict with early session decisions).

- **Soft (warning):** codebase investigation >~10 tool-calls without a conclusion → stop, formulate a decision from current data; ask for what's missing.
- **Hard stop:** after ~20 iterations with no working result → stop, describe what you tried and why it failed. Then `session-handoff` + new session with clean context, or ask the user.
- **Complexity-escalation ban:**
  - Needs a new package or abstraction layer → ASK first.
  - Touches >5 files → describe plan first, then edit.
  - Second fix of the same symptom → stop, find root cause (reproduction → root cause → fix → verification), not a third patch.
- **Dependent-package boundary — ask before extending.** A dependent package (`vendor/**`, path-repo `packages/**`, shared package) is **read-only** by default. Before adding a class/provider/interface/abstraction:
  - Survey package structure first for an existing extension point (contract, event, config, trait) — often nothing new is needed.
  - When in doubt, hand the go/no-go to a **separate small task/subagent** (survey reuse → judge by YAGNI/Rule of Three → verdict); don't mix recon with implementation.
  - Missing package functionality → **issue against the package**, temporary impl on consumer side (`app/`/`Modules/`) + `TODO`; don't edit the package "along the way".
  - Deep protocol (proposal → ADR → SemVer) → `package-contribution-protocol`.

## DENY circuit-breaker (permission denied)

A measured (not hypothetical) source of wasted moves: the agent gets `DENY(policy)`
on a command and re-issues the SAME form almost unchanged — pure repeated churn, no
progress. Rule: **a permission-gate denial is terminal for the exact command FORM
within the session** — never re-issue it verbatim. The next attempt must be one of:

1. **Atomic/allow-matchable alternative** — e.g. split a denied compound chain
   (`cd X; ls; grep …`) into separate atomic commands, each in the allow-set.
2. **Standard tool instead of manual action** — e.g. `skiller`/`maind` instead of a
   manual `rm` on a managed asset (skill, hub config).
3. **Explicit question to the user** if the form is ambiguously denied (unclear what
   in the allow/deny rules rejected it) — don't guess or keep varying the same form.

Check: if the next command is the same form that already got DENY'd this session,
differing only in arguments/paths — stop, that's a repeat, not an alternative.

## Shared resources & flaky tools

Long-lived daemons/servers (browser pools like perplexity-web-mcp, dev servers, LSP) serve OTHER agents concurrently.

- **No unneeded/destructive ops.** Never `pkill`/`kill -9`/`rm` a daemon's profile, socket, or Singleton lock. Prefer the gentlest path: reuse the live process, wait for it to free up. Rebuild/restart a shared service ONLY after confirming it's idle (no live agents) — else you crash others' sessions.
- **Respect concurrency.** Several tasks may hit one tool in parallel; that's expected — let them queue (or one tab/context per task), don't force-clear the resource.
- **Flaky tool → retry, don't panic.** An external tool (web search, network) can blip. Retry with backoff before giving up or changing the plan; a solid plan shouldn't collapse on one transient error. Configure retries/log/notify on the tool side, don't route around it.

## Related skills

- `task-brief-template` — scope and acceptance criteria fixed before start.
- `session-handoff` — exit session after the hard threshold.
- `complex-task-orchestrator` — decomposition and DoD for complex tasks; this skill = iteration discipline within them.
- `context-economy` — context economy on a dragging session (`/compact`, `/clear`, Plan→Clear→Execute).
- `package-contribution-protocol` — deep gate against dependent-package bloat (proposal → ADR → SemVer), when editing the package is agreed.

## Snippet

`snippets/claude-md-anti-drift.md` — ready-made rules block for the target project's CLAUDE.md (agent applies thresholds without an explicit skill call).

<!-- ru-source-sha256: 688d0a494558c7c7f588f5b4c8899f6e8edc41ba865ad68941b1d9e3539d235d -->
