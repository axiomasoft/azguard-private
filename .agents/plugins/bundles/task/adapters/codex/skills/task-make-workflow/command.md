<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/make-workflow.md
Canonical SHA-256: sha256:eec5bc213f3ca2ac5746bf4dfda7a9b47dd2358a6bd061689734d711bf40cb8f
Adapter: task.codex-command/1.0.17
-->
Activate only for an explicit request to create a workflow script. Ordinary development and research do
not need this path. User input: `$ARGUMENTS`. The route is pinned to implementation/high.

Copy and fill a canonical template; do not write orchestration from scratch.

## 1. Load the authoring contract

Use `general/workflow-craft` as the source of truth for template matching, the design/dev stage matrix,
and slot rules. Do not duplicate its rules in the generated workflow.

## 2. Select a template

Match the request to one or a pipeline of the four templates in `packages/task/workflows/README.md`:

- `wf-research-fanout.js`
- `wf-author-by-spec.js`
- `wf-audit-adversarial.js`
- `wf-dev-slice.js`

Classify the request as `design-only` or `dev` using the `workflow-craft` matrix. If neither one template
nor a pipeline fits, return to the main loop; do not fork template logic for the task.

## 3. Resolve slots

Fill facts available from the request and repository. Use `general/spec-interview` only for a missing
product decision or authority boundary:

- Ask one question at a time and branch on the answer.
- Read paths, contracts, and slice risks from the repository instead of asking the user.
- Recommend one answer with a short rationale.

Ask only template-specific questions such as slice gates, risk, frozen contracts, high-stakes judge-panel
eligibility, absolute paths, and slot contents. Do not ask whether required review or verification stages
should exist; the stage matrix decides that.

## 4. Generate from the template

Copy the selected template to `plans/<ID>/workflows/` and fill its slots:

- Keep parameters as constants in the header; `// SLOT:` markers are recommended.
- Embed structured configuration. Do not pass it through the named-workflow `args` string; launch by
  `scriptPath`.
- Use absolute paths because subagent working directories do not persist across calls.
- Require schema-backed structured summaries from control-flow agents.
- Give every loop-until a numeric iteration limit and escalate on exhaustion.
- Use a judge panel only for high-stakes fiscal, payment, tenant-isolation, or core-system stages.

## 5. Run a read-only static review

Have one read-only reviewer inspect, not execute, the generated script against exactly the invariants in
`packages/task/workflows/README.md` and the `workflow-craft` stage matrix. It must check:

- Orchestration logic still matches a canonical template; task-specific logic was not forked.
- Configuration is embedded, paths are absolute, and control-flow agents retain the required schema core.
- Fan-out uses `pipeline()` without a whole-wave barrier.
- Review and audit agents are read-only; authors stage only their own files.
- Dropped `agent()` results are collected instead of aborting the run.
- Top-level `return report` is accepted as the workflow runtime idiom.
- A `dev` workflow contains slice, implementation, tests-to-green, risk-adaptive review, inter-slice gates,
  and commit stages.
- A `design-only` workflow contains retrieval, design in the main loop, adversarial audit or DoR gate,
  repair, and re-audit, with no implementation, test, or SemVer stage.

The verdict is `GREEN` or `RED` with concrete invariant, location, and failure details. `RED` blocks delivery:
repair and rerun the review, with at most three passes; then escalate remaining violations to the main loop.
Only `GREEN` releases `plans/<ID>/workflows/wf-<code-name>-<purpose>.js`.

Return the script path, workflow type, and reviewer verdict. For `RED`, return violations and repair status
without presenting the script as ready.
