# Codex model routing — 2026-07-21

## Sources

- OpenAI Model guidance: https://developers.openai.com/api/docs/guides/latest-model
  (verified 2026-07-21).
- OpenAI Models: https://developers.openai.com/api/docs/models
  (verified 2026-07-21).
- Local provider-command parity:
  `/home/vostrikov/projects/packages/swissknifeman/packages/task/docs/reference/provider-commands.md`
  (read 2026-07-21).
- Project-native Codex agents: `.codex/agents/*.toml` (read 2026-07-21).
- Installed plugin manifest and adapters:
  `/home/vostrikov/.codex/plugins/cache/swissknifeman/task/0.3.0/`
  (verified installed/enabled 2026-07-21).

## Verified facts

- `gpt-5.6-sol` is the frontier member of the GPT-5.6 family and is recommended for
  complex professional reasoning/coding. RAG:✅ 2026-07-21 (OpenAI Model guidance).
- `gpt-5.6-terra` balances capability and cost. RAG:✅ 2026-07-21 (OpenAI Model guidance).
- `gpt-5.6-luna` is optimized for cost-sensitive, high-volume work. RAG:✅ 2026-07-21
  (OpenAI Model guidance).
- The local Codex adapter maps the plan protocol's neutral routes as follows:
  `frontier/* → gpt-5.6-sol/*`, `implementation/* → gpt-5.6-terra/*`; the project's
  read-only/exploration agents already use `gpt-5.6-luna`. RAG:— (repo-grounded:
  provider-commands.md; `.codex/agents/*.toml`).
- `task@swissknifeman` 0.3.0 exports Codex adapters for `task:plan-run`,
  `task:plan-exec`, `task:plan-close`, `task:plan-audit` and `task:plan-design`.
  A session started before plugin installation/update must be restarted because its
  available skill set is already fixed. RAG:— (repo-grounded: installed plugin manifest
  and adapter `SKILL.md` files).

## Routing verdict for the remaining plan

| Work class | Model / effort | Use |
|:--|:--|:--|
| Frozen-spec implementation | GPT-5.6 Terra / medium or high | P4.8, P4.7, P4.9–P4.10, P4.3–P4.6, P5.2–P5.3 |
| Open design / adversarial audit | GPT-5.6 Sol / high or xhigh | P5.1, phase audits, correctness-critical independent review |
| Read-only recon / deterministic verification | GPT-5.6 Luna / low | grep/map, isolated test runs, log compression; never the final verdict on migration/concurrency/release correctness |

The provider name is a projection, not the plan's semantic route. `plan.md §3` remains
neutral for all open items; if the provider family changes, only this projection and launch
blocks need refreshing.

## Review economy

- Do not spend Sol on routine implementation against already frozen specs.
- Use one Sol/xhigh phase audit after P4 and after P5.
- Add a Sol/high independent diff review before closing P4.8, P4.7, and P4.4 because
  they touch cross-driver data integrity or concurrency; review is read-only and fixes return
  to Terra/high.
- Let Luna handle supporting read-only work only when its output is deterministically checked
  or consumed by a stronger reviewer.
