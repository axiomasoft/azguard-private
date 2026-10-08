# P6.4 review — grok/grok-4.7/500k/high

- Launch: `plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high` (argv unchanged), brief exec/v1 on stdin, stream `stream.jsonl`.
- Reviewer session: 01a11a23-9639-7301-9a19-b142e7379a9c (`~/.grok/sessions/<cwd>/<session>`): `current_model_id` grok-4.7 (usage reports the backend variant grok-4.7-build), `signals.json` contextWindowTokens 500000 (used 141479). Effort high was passed in argv and is the config default; the session record does not store the effort.
- Verdict: ATTENTION — 3 findings (2 Major, 1 Minor); full JSON with reproductions in `findings.json` and the stream.

Executor confirmation (own runs):
1. Major, storage with an unconfigured connection aborts the run — CONFIRMED: regression `DoctorTest` "reports a storage whose connection is not configured instead of failing the run" RED without the fix (error `Database connection [no_such_connection] not configured`), GREEN with it. Fix: `Doctor::storages()` survives a registry that cannot be built; `config.valid` reports every storage whose connection `database.connections` does not configure (`invalid_configuration.storage`), exit 1.
2. Major, password shorter than 4 characters printed — CONFIRMED: regression `DoctorTest` "V104 removes a short password …" RED (`password pw via mysql://root:pw@db`), GREEN after the fix. Fix: every secret value is redacted; one shorter than 4 characters only between non-alphanumeric characters.
3. Minor, findings section P6.4 missing — CONFIRMED (it is written at closing, after the review); added to knowledge/findings-P6-execution.

Residual risk noted by the reviewer and kept: `DoctorFinding::redacted()` is public with an @internal method tag, like the other @internal methods of public classes; a database username is not treated as a secret (V104 names passwords and DSNs).
