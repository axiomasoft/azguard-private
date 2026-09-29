<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/plan-audit.md
Canonical SHA-256: sha256:03aec4d7e720de6c50f4c10b7d2ecf8d596cca9077d8b6940e096a255be06f83
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
export TASK_CODEX_ROUTE_EVIDENCE="$(PYTHONPATH="${TASK_CODEX_PLUGIN_ROOT}/lib:${TASK_CODEX_PLUGIN_ROOT}/../.."   python3 -m task.route_evidence_codex capture   --expected-invocation '$ task:plan-audit <arguments>' --expected-cwd <repo-cwd>)"
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
`runtime/plan-protocol/references/review-mode.md` from the configured Task package root.
The full schema canon and snippets are on-demand references, not mandatory review context.

Audit is adversarial and read-only over product work. Read the declared assurance, the Task Contract's
sole exact executable Routing compiler result, and prior terminal evidence; compare only the changed-risk delta.
For `design`, first run `scripts/plan-work.py design-gate --plan-dir <dir>`. Require a completed
`finalize-design --finish` and audit the returned current fingerprint across every declared phase,
item, dependency and execution-sheet row. Persist the audit journal event with that fingerprint
using the existing `--fingerprint` field. A changed design input makes the verdict stale; after
correction, repeat finish and the design audit. GREEN is the only result that opens plan execution.
Before phase audit, consume one accepted, unsuperseded `audit-admission/v2` decision whose bounded
residual-risk question, target phase, material candidate fingerprint, audit run, owner-message
provenance, and scope digest all match. Foreign, stale, ambiguous, or already consumed consent does
not admit a run. Legacy `required`/`auto`/`light`, risk prose, integration seams, missing assurance,
or an agent-authored grant are advisory only. Without matching consent, preserve a bounded
recommendation and leave GREEN evidence eligible for direct close; missing or RED acceptance
returns owning repair.
Persist the runtime candidate snapshot with
a GREEN verdict. A post-GREEN mismatch is `audit-impact/v1`: gate-rebind, delta, justified full,
evidence gathering, or owner review—never bare stale/full. This classification scopes native repair
and evidence; it does not authorize another audit. Fresh deterministic evidence is reused.
Before GREEN, regenerate audit-owned carriers once, rebuild the candidate and persist
`task.plan_evidence.audit_finalization()` with the exact `phase_close_gate` admission; product
paths must remain byte-identical between the product and closure anchors.

Use `task.plan_lifecycle.select(task.plan_lifecycle.LifecycleInput(audit=True))` as advice: at most one deterministic audit-owned bookkeeping
repair is allowed. A product defect returns one exact owning continuation; it does not trigger a
second audit, full replay, product write, or fresh root merely because this command is an audit.
Material risk selects one independent review path. Persist the semantic Next through `plan_delivery`.
One consent admits one bounded run; its in-progress journal event reserves it and its terminal event
consumes it. A repeat needs a new question, candidate/run-bound consent, and material hypothesis.

Audit does not bypass terminal admission: an applicable lease can only emit `waiting-owner` or its
typed continuation until a fresh terminal receipt and exact final fragment exist.

Persist a product finding as audit-attention (or audit-red) with decision action
`return-owning-continuation`, evidence IDs, reason_code, scope_class=<owning item>,
recovered=false, terminal=false,
and exact exec-items/run-items owning continuation. Reviewer completion leaves the phase open.
