# P3.5 fuzz findings

Targets: `PermissionGrammar` (predicates, assertions, `splitFull`), `PermissionKey::parse`, `PermissionPattern`,
`RoleKey::of`, `IdentityCodec::canonicalId` / `assertTypeAlias`, `StateToken::fromString`, `CodeStateToken::fromString`.
Files: `tests/Unit/Fuzz/GrammarFuzzTest.php`, `tests/Unit/Fuzz/StateTokenFuzzTest.php`, generator
`tests/Fixtures/Fuzz/FuzzInput.php`, corpus `tests/Fixtures/Fuzz/corpus.json`.

Properties: predicate = assertion (true iff no throw, typed domain exception otherwise); parse round-trips; accepted tokens
re-read equal; an uncaught non-domain exception, a PHP warning or an input over 50 ms fails the test.

| Target | Input (escaped) | Outcome | Fix commit |
|:--|:--|:--|:--|
| all | none found by seeds 1-3 x 500 iterations + corpus, seed 11 x 20000 (group `extended`) | no defect | n/a |

Not covered (no parser of that shape in the core): custom field codecs, table prefix grammar and enum aliases are covered by
their feature tests (`ListColumnFieldTest`, `PanelCompiler` table grammar cases, `FilamentGenerateTest`); the CLI JSON of
`azguard:doctor --json` is pinned by `tests/Feature/Diagnostics/DoctorCommandTest.php` against `tests/Fixtures/Diagnostics/doctor.json`.
Coverage-guided external fuzzers are not in the toolchain.
