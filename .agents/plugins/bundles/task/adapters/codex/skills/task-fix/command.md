<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/fix.md
Canonical SHA-256: sha256:985bb315d8635899baf69d0ed9758e459128c9cbe4e20abb08c9db39d9bc9308
Adapter: task.codex-command/1.0.17
-->
This is a SMALL incremental repair. User input: `$ARGUMENTS`.
Apply the shared opt-in contract in `references/intent-routing.md` from the installed Task package.
Complete the repair directly: design, a plan, phase audit, and plan close are not completion prerequisites;
old task-file instructions do not override the current owner request. The route is pinned to implementation/medium.

Profile `fix`:

1. Use one pass without fan-out or subagents; coordination is not justified for a small repair.
2. Read the relevant slice rather than whole files. Filter logs by identifier, inspect the last useful stack
   frame, and use the diff as review context. Point to material and read it on demand instead of pasting it.
3. Make the smallest sufficient change and verify changed behavior. Do not add speculative abstractions.
4. If an external fact is uncertain, use the `verify-claims` ladder (context7, then `perplexity-web`).
5. Record initial `git status` and inspect overlapping diffs. A dirty baseline is not a blocker by itself:
   preserve other people's work and stop only at a concrete incompatible ownership conflict. Do not push or
   open a PR without an explicit request.
