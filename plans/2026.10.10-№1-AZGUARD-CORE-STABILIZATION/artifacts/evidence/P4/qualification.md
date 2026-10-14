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
