# P4.4 environment witness

- Project `/home/vostrikov/projects/packages/azguard`; clean baseline commit `554126502890563c02a3ef9b5a19fd71982c3cd0`.
- Root native route: `gpt-6.1-sol`, high; route-run receipt `f2263a3b5418e36a4ec93852457cc5ba9a7f2f148d9123b75e5e8768b3e45456`, session `01a107ea-3165-7150-90ff-8265647e49b2`. `-a never -s danger-full-access`.
- Canonical SKM `/home/vostrikov/projects/packages/swissknifeman`, HEAD `e59e4f2ca3aab23b56e846bb59702d46037c408c`; Task 0.28.0. Cached plugin is not used for execution.
- PHP 8.4.1; Python via `python3` (`python` alias absent).
- Grok 1.0.46 (2765805b9442), native ACP model grok-4.7, high, context_window 500000. Cheap initialize/new/set_model only; no prompt/product/history transmitted. Native summary is copied alongside this witness, original sha256 `976637ad64240a79b2b4c82402130f6f0b53b373624ed726b7db8661fae726e1`.
- DB endpoints observed from Docker: PostgreSQL 127.0.0.1:25432, MySQL :23306, MariaDB :23307. Healthy containers; host PDO SELECT 1 succeeds for isolated `azguard_test` on all three. Tests/TestCase checks server DB `_test` suffix before setup; SQLite uses `:memory:`. Schema tests create/drop isolated package schema themselves.
- `.env` contains stale ports 5432/3306. Validation commands explicitly bind observed ports; no dev database or unrelated config changed.
- Forked child route capture cannot provide root evidence: rollout includes child+parent session_meta. Root prepare succeeds with native provider-thread-state proof. This is recorded as a child capture limitation, not a root route failure.
- Canonical review packet helper does not expand brace/glob Files entries. Root supplies every concrete changed/new/deleted product file plus required dependencies to `--path`; completeness is checked before launch. Owning SKM defect recorded separately; no future item repair is admitted here.
- Evidence writer: root only. Each command has unique numbered log/JSON with before/after product map and SHA256. Mutation during a command marks it unstable; unstable runs cannot prove a final candidate.
- Preliminary runs `004-engines-mysql-preliminary` and `004-targeted-corrected` overlapped due to orchestrator proceeding after a live session poll. Both are excluded from acceptance. Stable full qualification runs are serialized after source/test freeze; runner lock prevents another overlap.
