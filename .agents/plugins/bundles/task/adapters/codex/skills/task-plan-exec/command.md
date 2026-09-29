<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/plan-exec.md
Canonical SHA-256: sha256:9cf2b9df3668132211fa5051765f1a2c870022992f09db15c40cde97e016cdb8
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
export TASK_CODEX_ROUTE_EVIDENCE="$(PYTHONPATH="${TASK_CODEX_PLUGIN_ROOT}/lib:${TASK_CODEX_PLUGIN_ROOT}/../.."   python3 -m task.route_evidence_codex capture   --expected-invocation '$ task:plan-exec <arguments>' --expected-cwd <repo-cwd>)"
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


## Codex snapshot transport guard — provider-specific

Use `plan-work.py prepare` for the compact execution capsule. Its runtime validates the immutable
five-carrier snapshot internally; do not transport full plan/phase/bundle/state into model context.
`PLAN_CONTEXT_TRUNCATED`/`PLAN_CONTEXT_DIGEST_MISMATCH` remain real errors, not prompts to reread
everything. Open a full carrier only for a named ambiguity or repair diagnostic.

Read `runtime/plan-protocol/references/common-principles.md` and
`runtime/plan-protocol/references/execution-mode.md` from the configured Task package root.
The full schema canon and snippets are on-demand references, not mandatory execution context.

Execute the admitted Routing group or ready subset of a recommended group for `$ARGUMENTS`.
For execution-sheet plans, `prepare` requires all declared phases detailed, finish recorded and
a GREEN design audit bound to the current authored inputs. A stale or missing audit returns a typed
refusal before the durable execution start. The selected bundle is built at start.
Undershoot stops before mutation; bounded pinned work also rejects unrequested expensive overshoot.
Activation requires an explicit owner Task invocation, as defined in the
shared [intent routing canon](../references/intent-routing.md).

## Continuous execution

After each GREEN item, close the item and any directly closable GREEN phase in this invocation,
then compute Next from the resulting state. Continue a compatible ready successor inside the
owner-authorized admitted scope in this root. Local reversible repair stays in this invocation.
If the owner requested only the completed scope, stop product writes and persist lifecycle
`scope-complete` with its checkpoint: the reducer retains `continue-root` with reason
`authorized-scope-complete`. A chat final reports the successor as a recommendation; it grants no
execution authority and does not require a new process. Never invent work when the objective is done.
An explicit request for a new session updates lifecycle to `owner-requested-new-session`; persist a
fresh post-close checkpoint and render exactly one native launch for that successor. A routine
item/phase transition or test failure does not imply a session boundary. A real external wait keeps
its time/predicate gate visible. Design-only/review-only requests remain separate deliverables.

## Shared lifecycle

`task.plan_lifecycle.select(task.plan_lifecycle.LifecycleInput())` is advisory impact selection. It owns no carrier or enum: admission
stays in `task.plan_admission`/`task_contract`, route proof in `route_evidence`, recovery in
`plan_repair`/`plan_blocking`, review in `review_policy`/`plan_gates`, projection in
`plan_projection`, context in `plan_continuity`, and semantic Next/delivery in `plan_delivery`.
Commands must not copy those algorithms. Choose any safe order consistent with their data
dependencies, Scope and Acceptance; recommended Next is not exclusive execution authority.

1. Call `scripts/plan-work.py prepare --plan-dir <dir> --item <Pn.m> --command plan-exec
   --actor <actor> --session-id <session>` once. It owns recovery, route capture, snapshot validation,
   batch admission, evidence graph, `task_contract.pre_mutation()` and durable start. Consume its
   compact execution capsule; full plan/phase/bundle/state are internal runtime inputs.
   `PLAN_CONTEXT_TRUNCATED` and `PLAN_CONTEXT_DIGEST_MISMATCH` still block before product writes.
2. Retain the returned run identity and route-run receipt. A retry reuses the durable start;
   a changed route or scope needs fresh admission. Open full carriers only for a named ambiguity
   or diagnostic. A real `SELF_INVALIDATING_EVIDENCE_ANCHOR` requires separate anchors; routine
   bookkeeping does not. Do not repeat preflight merely because the workflow advances a step.
3. Implement only Scope Included. Ordinary low-risk work uses a self-check and affected tests;
   bounded integration adds the necessary package/integration check. Independent review requires a
   concrete item risk—security/auth, destructive migration, irreversible external action,
   payment/accounting, concurrency/shared state, public API/schema, low-confidence impact, or release
   authority—or an explicit owner requirement. Uncertainty gathers evidence; it does not default to
   full review.
4. A foreign baseline stays visible but non-gating unless ownership/dependency closure intersects.
   Audit is read-only: it returns one owning continuation for a product finding and never starts a
   second audit/full replay without a new material hypothesis.
5. After GREEN acceptance, persist evidence and the terminal journal event, then call
   `scripts/plan-work.py finalize --plan-dir <dir> --item <Pn.m>` to converge changed terminal projections, the
   selected successor bundle, freshness and delivery receipt. Close the item in this invocation. If this is the last item, phase gates are
   GREEN, and no audit was explicitly admitted, create its phase closure before deriving Next. Derive and validate
   one semantic Next through `plan_delivery` from the resulting state and dependencies; never emit
   `plan-close` for the current result or repeat `plan-exec`/`plan-run` for the current item. Use one
   scoped commit. A second bookkeeping commit requires a real session/ownership boundary or
   digest-input collision.

Have current GREEN evidence for every declared Validation carrier. Evidence remains current only
while its relevant product/test/tool/config inputs and environment are unchanged; rerun the affected
delta after change and rerun a live gate at its live boundary. A closure/bookkeeping-only delta needs
one regeneration and structural check and does not invalidate independent product evidence. An
explicit Validation/security/release carrier remains binding until transparently amended through the
existing contract rules; do not silently skip it.

Close only with green evidence, `task_contract` allow at both write sites, generated views/lint, and
`plan-delivery --kind terminal`. For a terminal phase use `plan_assurance.evaluate()` through
`phase_audit_policy`: GREEN evidence direct-closes by default. An `audit-phase` Next requires an
explicit residual-risk question and fresh owner consent bound to the phase, candidate fingerprint,
and audit run; legacy `required`/`auto`/`light`, Routing Review, item count, integration seams, or risk
prose alone are advisory. Missing or RED evidence remains repair work for the current executor in
this invocation, never audit admission or a routine handoff to the repository owner. Ask the owner
only at a concrete authority or product-decision boundary.
Batch handoff retains its Batch and Context; a fresh-session boundary carries one provider-native
launch block for the actual post-closure successor.

## Terminal admission

While the durable run is open, report partial progress through commentary and continue authorized
work. The enabled Stop adapter invokes `plan-terminal-admission.py check-open --project-root <cwd>
--provider-session <session>` before the optional lease check, including when the Stop payload has
no Task context. `TASK_OPEN_RUN_FINAL_FORBIDDEN` is repaired by continuing work or persisting a
legitimate terminal block/checkpoint; it never authorizes fabricated closure. Respect owner stop.

After route/run admission, an applicable provider adapter may arm `terminal-admission-lease/v1`
through the worktree-local `plan-terminal-admission.py`; it refreshes only on material progress or
a typed `waiting-owner` or `waiting-external` decision. External waits preserve the observation
checkpoint and suppress terminal admission until actual time/predicate evidence allows continuation.
At a Stop boundary the adapter passes the typed P1.3 continuity
decision, rebuilt terminal receipt and in-memory proposed final fragment to
`terminal_admission.evaluate()`. `final_allowed=true` requires exact identity and byte-exact
`plan_delivery.required_final_message(receipt)`; no lease is `NOT_APPLICABLE`. The reducer stores
only hashes and reason codes. Providers render its verdict but never decide terminality.

At final, always report the exact semantic Next from the receipt. Render the byte-exact shared final
fragment only for a terminal-admission lease, cross-session/provider handoff, owner/external wait,
or release/public boundary. Same-session local closure uses the internal receipt and a concise result.
