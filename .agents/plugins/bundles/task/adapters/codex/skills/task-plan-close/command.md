<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/plan-close.md
Canonical SHA-256: sha256:03098a126a5da720830263c8e5149e0f082b227c9a02261c107b2fdf20ad386a
Adapter: task.codex-command/1.0.17
-->
## Native Codex launch-block — required only at a real user-facing boundary

This generated runtime is the Codex provider projection. Render only the Codex-native launch;
never copy Claude Code slash syntax as the executable invocation and never print both providers.
`$ task:…` is an invocation inside the **current** Codex session, not a fresh launch.
When the typed Context is `continue-root`, keep executing the compatible ready successor within
the owner-authorized scope in this root. If that scope is complete, report the exact successor as a
recommendation for this chat; a final answer does not require a new `codex` process. A command, phase, directory, local repair, or ordinary test failure is not a session
boundary.

At a fresh-session boundary, keep Batch/Model/Thinking/Context/Суть in the metadata table,
but never put the command in a table
cell or column. Immediately after the table print `**Native Codex command:**` and a fenced `bash`
block which explicitly pins the repository cwd, mapped model, reasoning effort, approval policy,
and sandbox:

```bash
codex -C <repo-cwd> -m <model-selector> -c 'model_reasoning_effort="<effort-selector>"' -a never -s danger-full-access '$ task:<command> <arguments>'
```

Prefer one physical command line. If intentional formatting uses multiple shell lines, terminate
every continued line except the last with `\`; never rely on Markdown visual wrapping.

**Final-response guard:** continue compatible work inside the admitted owner scope. At an actual
chat stop, include the exact shared receipt recommendation even when no terminal-admission lease
is installed. `scope-complete` retains `continue-root`; it does not authorize the successor or
require a fresh launch. An explicit new-session request updates the persisted decision to
`cold-start-root: owner-requested-new-session` with a fresh post-close checkpoint. Then include the
copyable native Codex block after the metadata table. Waiting continuations retain the exact gate;
never present them as an unconditional command to run now. A completed objective needs no invented
successor. Render only the active provider.

Derive the class and effort from the next item's Routing before rendering it; for `plan-run`,
do not reuse the preceding session's route. Current Codex mapping: economy=gpt-5.6-luna, implementation=gpt-6-sol, frontier=gpt-6-astra;
low=low, medium=medium, high=high, xhigh=xhigh. At a fresh-session boundary, keep the provider-neutral Next too; its
`$ task:…` form alone or an unpinned `codex` command is not a native fresh launch.

## Codex current-route binding — provider-specific

Before the first product-journal mutation, capture the effective route for this exact command:

```bash
export TASK_CODEX_PLUGIN_ROOT="$(codex plugin marketplace list --json | python3 -c 'import json,sys,pathlib; data=json.load(sys.stdin); roots=[item["root"] for item in data.get("marketplaces", []) if item.get("name")=="swissknifeman" and (pathlib.Path(item["root"])/"packages/task/lib/task").is_dir()]; len(roots)==1 or sys.exit("task runtime unavailable: expected one marketplace root with packages/task/lib/task"); print(roots[0]+"/packages/task")')"
export TASK_CODEX_ROUTE_EVIDENCE="$(PYTHONPATH="${TASK_CODEX_PLUGIN_ROOT}/lib:${TASK_CODEX_PLUGIN_ROOT}/../.."   python3 -m task.route_evidence_codex capture   --expected-invocation '$ task:plan-close <arguments>' --expected-cwd <repo-cwd>)"
```

Replace `<arguments>` and `<repo-cwd>` with the exact current invocation payload and repository
cwd. A non-zero exit is `ROUTE-UNVERIFIABLE`; do not start or mutate the product item.

For Codex, `task.route_evidence_codex.capture_current(...)` resolves the current
`CODEX_THREAD_ID` first and validates the latest provider-written `turn_context`: concrete
model, reasoning effort, cwd, approval policy, sandbox policy, and the exact current Task
prompt. The resulting actual-route record carries `source="provider-thread-state"` and
`source_version="codex-turn-context-v1"`.

This path is valid both after a full native launch and after the Codex clear command, which
starts a fresh chat inside the same CLI process. A model or reasoning change before product
journal start is therefore checked from the current chat state, never from stale process argv.
If persisted thread state is genuinely absent, exact ancestor argv remains a compatibility
fallback with `source="cli-flag"`. Malformed/mismatched thread state fails closed; config,
doctor output, actor prose, and self-attestation remain forbidden substitutes.

This provider binding defines how Codex satisfies the D24 write-site checks in the canonical
command below. The projected contract snippets consume exactly
`json.loads(os.environ["TASK_CODEX_ROUTE_EVIDENCE"])`; do not recreate route evidence from
placeholders, `codex doctor`, configuration, or agent prose. Its fresh-native-only wording constrains the `cli-flag` compatibility fallback;
its private-rollout prohibition forbids manual probing but not this validated adapter-owned
read; neither rule prohibits a verified cleared chat or overrides current `turn_context`
evidence.

Invocation payload: `$ARGUMENTS`.

Read `runtime/plan-protocol/references/common-principles.md` and
`runtime/plan-protocol/references/execution-mode.md` from the configured Task package root.
The full schema canon and snippets are on-demand references, not mandatory reconciliation context.

Reconcile only declared terminal evidence. `task_contract` owns both write-site predicates and the sole
exact executable Routing compiler;
`plan_assurance.evaluate()` is the sole assurance/impact reducer behind `phase_audit_policy`: GREEN
evidence direct-closes by default, while an audit needs a bounded residual-risk question and fresh
owner consent bound to its target phase, material candidate and audit run. Legacy
`required`/`auto`/`light` remains readable as advisory migration input and never admits audit;
missing or RED evidence returns owning repair. For an admitted post-GREEN audit, impact reduction
classifies `gate-rebind|delta|full|gather-evidence|owner-review` from a runtime-built candidate but
does not authorize another audit: affected validation is native repair, and a new audit needs fresh
candidate/run-bound owner consent;
`plan_projection` regenerates
only changed carriers once; `plan_continuity` owns context; and `plan_delivery` owns one semantic Next
and provider-native launch rendering. Do not use a command name, bookkeeping drift, Routing Review,
item count, or historical deviation as a full-replay/fresh-root trigger. Unknown impact blocks close
with its exact continuation; it never implicitly authorizes full replay.
For a post-audit phase close, consume the persisted `audit-finalization/v1` bound to the rebuilt
candidate; absence or a non-allow close admission refuses closure.

Normal close is one scoped commit. A second commit requires a real ownership/session boundary or digest-input collision. A red
carrier remains owning repair work; an audit product finding returns its exact continuation.
Explicitly invoked standalone `plan-close` remains supported. Execution commands close their own
GREEN item and eligible last-item phase before handoff; they never launch this command for the result
they just produced. Reuse unchanged product evidence, rerun only a changed relevant delta or a live
gate at its boundary, then compute Next from the post-closure state.

If a terminal-admission lease applies, `plan-close` provides the freshly rebuilt delivery receipt
and exact machine-authored final fragment to its provider-neutral reducer. A missing, stale or
mismatched lease never fabricates a terminal allow; recovery is a named re-arm/resume action.
