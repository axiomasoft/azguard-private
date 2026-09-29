<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/plan-run.md
Canonical SHA-256: sha256:578abe97a51a07d6cc7f7f75488478458db09104f2b9ffca77539fe6b40cd595
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
export TASK_CODEX_ROUTE_EVIDENCE="$(PYTHONPATH="${TASK_CODEX_PLUGIN_ROOT}/lib:${TASK_CODEX_PLUGIN_ROOT}/../.."   python3 -m task.route_evidence_codex capture   --expected-invocation '$ task:plan-run <arguments>' --expected-cwd <repo-cwd>)"
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

Выполни «$ARGUMENTS» через canonical `task:plan-exec` lifecycle, но сохрани текущую
provider-attested route и carrier `task:plan-run`. Не переключай модель вручную. Нормальный
low-risk выбор выражается один раз:
`task.plan_lifecycle.select(task.plan_lifecycle.LifecycleInput())`. Durable старт связывает маршрут
с `route-run-receipt/v1`; не создавай второй route contract в prompt.

1. Вызови `scripts/plan-work.py prepare` один раз с `--command plan-run`. Он владеет recovery,
   batch/route/authority admission, evidence graph, generated carriers, durable start и единым
   execution snapshot. Повторный запуск продолжает тот же durable run.
2. Реализуй только Scope Included. Выбирай порядок чтения и работы самостоятельно. Для обычной
   обратимой работы достаточно self-check и affected tests. Расширяй validation или независимое
   review только из-за конкретного риска либо явного требования владельца.
3. Сохрани GREEN evidence и terminal journal event, затем один раз вызови
   `scripts/plan-work.py finalize`. Runtime обновит затронутые views, следующий ready bundle и
   delivery receipt. Не повторяй preflight и не перечитывай полные carriers без именованной
   неоднозначности.
4. Продолжай разрешённую работу в этой сессии. Остановись только на реальной owner/external
   boundary, недостающем решении или исчерпанном контексте. Один scoped commit — штатный путь.

Не выдумывай evidence и не обходи security/auth, payments, destructive/irreversible, public
contract или shared-state boundaries. Existing dirty changes принадлежат владельцу.

В финале обычного same-session local closure дай краткий результат и точный semantic Next из
receipt. Byte-exact shared final fragment нужен только когда его требует terminal lease,
cross-session/provider handoff, owner/external wait или release/public boundary.
