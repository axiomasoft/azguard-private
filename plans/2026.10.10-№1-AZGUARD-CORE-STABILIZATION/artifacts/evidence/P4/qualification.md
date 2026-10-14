# P4.2 qualification

Final sha: `4a429068883a2e47642cb12272445beb976081e5` (pushed to origin `feat/1.0-candidate`). Local runs: clean git worktree at that sha with the dependency versions CI resolves (Laravel 13.36, Filament 5.10.2, Symfony 8.1), PHP 8.4, ports 25432/23306/23307/26379; the main checkout carries the owner's uncommitted test-refactor files and older vendor, so it is not used as evidence. Logs: `logs/`, one `Vn.json` evidence per check next to each log.

| check | sha | command | exit | result | log |
|:--|:--|:--|:--|:--|:--|
| V1 | 4a429068883a2e47642cb12272445beb976081e5 | pest tests/Arch | 0 | 179 passed, 67 s | logs/V1.log |
| V2 | 4a429068883a2e47642cb12272445beb976081e5 | pest Parity, InactiveCost, Regression | 0 | 147 passed, 54 s | logs/V2.log |
| V3 | 4a429068883a2e47642cb12272445beb976081e5 | composer analyse, lint:check, refactor:check, api:check | 0 | all passed | logs/V3.log |
| V4 | 4a429068883a2e47642cb12272445beb976081e5 | composer test:types | 0 | min 98 passed | logs/V4.log |
| V5 | 4a429068883a2e47642cb12272445beb976081e5 | composer test | 0 | 4797 passed, 452 s | logs/V5.log |
| V6 | 4a429068883a2e47642cb12272445beb976081e5 | /usr/bin/php8.3 pest … | 255 | vendor requires PHP >= 8.4.1; recorded as the item allows. Evidence: CI jobs P8.3 × L11/L12/L13 × lowest/stable green on this sha | logs/V6.log |
| V7 | 4a429068883a2e47642cb12272445beb976081e5 | composer test:db -- pgsql --parallel | 0 | 762 passed, 99 s | logs/V7a.log |
| V7 | d196325a | composer test:db -- mysql --parallel | 0 | 1832 s (later commits are test-only changes outside group database) | logs/V7b.log |
| V7 | 4a429068883a2e47642cb12272445beb976081e5 | mariadb --parallel | CI | not run locally: the owner accepted the green CI job "DB lane · mariadb · L12/L13" (Tests run 38550711308) | logs/V7.log |
| V8 | 4a429068883a2e47642cb12272445beb976081e5 | engines pgsql/mysql/mariadb | CI | the owner accepted the green CI step "Engine qualification without skips" of all six DB lane jobs | logs/V8.log, logs/V8-ci-steps.tsv |
| V9 | 4a429068883a2e47642cb12272445beb976081e5 | composer test:replica, test:redis, test:extended | 0 | replica 1 test, redis 2, extended 2 (20029 assertions, 94 s) | logs/V9a.log, V9b.log, V9c.log |
| V10 | 4a429068883a2e47642cb12272445beb976081e5 | consumer-fixture full, --with-filament, core-only | 0 | three archives OK, 222 s | logs/V10.log |
| V11 | 4a429068883a2e47642cb12272445beb976081e5 | composer docs:language, docs:php | 0 | passed | logs/V11.log |
| V12 | 4a429068883a2e47642cb12272445beb976081e5 | CI evidence ci-runs-4a429068883a2e47642cb12272445beb976081e5.json | 0 | Tests, Static Analysis, Code Style, Zizmor, CodeQL, Tests (nightly) succeeded | logs/V12.log |

Zizmor, CodeQL and Tests (nightly) were started with `gh workflow run --ref feat/1.0-candidate` (they do not run on a feat/** push). The failures that came up and their fixes are in fixes.md (F1–F5).

# P4.8 qualification of the candidate

Candidate C = `3d789538c65710ff77cc13bfc3109312b7f9e455` (pushed to origin `feat/1.0-candidate`; fast-forward pushes only). The first C (b0fb0b8c) failed Tests on F6 (fixes.md); the final C is the fix commit 3d789538. No production code (`packages/**`, `bench/**`) changed after the last P4.6 commit 8c39e224 (`git diff --name-only 8c39e224 C -- packages bench` is empty), so the P4.6 bench reference stays valid.

Owner instruction in the session: no local test or long runs; the evidence is GitHub Actions on C. The main checkout carries owner WIP, and a worktree with the shared vendor autoloads the main tree's packages (V1/V2 attempts failed for that environmental reason, logs deleted), so local V1, V2, V4–V9 are replaced by the same commands' CI jobs, as in P4.2.

| check | sha | evidence | result |
|:--|:--|:--|:--|
| V1 tests/Arch | C | Tests run 38603960397, jobs P8.3/P8.4/P8.5 × L11–L13 (full pest incl. tests/Arch, fail-on-skipped) | 15 matrix jobs success |
| V2 Parity/InactiveCost/Regression | C | same Tests run (part of the full suite) | success |
| V3 analyse, lint, refactor, api:check | C | Static Analysis run 38603960357 and Code Style run 38603960149 success; local `composer analyse && lint:check && refactor:check && api:check` exit 0 (logs/P48-V3.log) | success |
| V4 types, coverage | C | Tests run job Coverage success (4918 tests, `--coverage --min=85`); the type-coverage gate is the Static Analysis step `pest --type-coverage --min=98` | success |
| V5 composer test | C | Tests run, P8.4 jobs | success |
| V6 DB lanes | C | Tests run: DB lane pgsql/mysql/mariadb × L12/L13 (6 jobs) | success |
| V7 engines | C | Tests run: step "Engine qualification without skips" of the six DB lane jobs; Tests (nightly) run 38604026382 (all parity seeds on pgsql, mysql, mariadb, extended scale tests) | success |
| V8 replica/redis/extended | C | Tests run: "PostgreSQL · authority replica", "Redis · cross-process cache"; nightly "Extended · scale tests" | success |
| V9 consumer archives | C | Tests run: "Consumer fixture · built archives" (core, core+Filament, core-only, relocated-audit) | success |
| V10 docs gates, release-preflight and guard tests | C | local `composer docs:language && docs:php && bash bin/test-release-preflight.sh && bash bin/test-compat-baseline-guard.sh` exit 0 (logs/P48-V10.log) | success |
| V11 CI | C | ci-runs-3d789538c65710ff77cc13bfc3109312b7f9e455.json: Tests, Static Analysis, Code Style, Zizmor, CodeQL, Compatibility, Tests (nightly) success | exit 0 |
| V12 invariants | C | python check of tests/Arch/invariants.json | exit 0 |
