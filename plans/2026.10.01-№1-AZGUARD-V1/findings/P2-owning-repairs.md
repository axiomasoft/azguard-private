# Owning repairs P2 — P2.8 / R1 — 2026-10-03

P2.8 repeat: **GREEN / closed-green** после проверки всех восьми Validation carriers. R1 исправлен; **P2 остаётся RED**, R2/R3 открыты и принадлежат отдельному repeat P2.5. Исторический `findings/P2-review.md` и review code не изменены; P2.10 не повторялся, P3 не исполнялся.

Run `cd404c856b90f0c0efe4eeec333e34110477d0262f2fe044082bb5ac046d2b08`, attempt 2; настоящий CODEX_THREAD_ID `01a10309-5f0f-7242-8a1f-a88409504c71`. Native capture: cwd `/home/vostrikov/projects/packages/azguard`, `gpt-6.1-sol`, frontier/high, exact invocation `$ task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.8`, approval never, sandbox danger-full-access. HEAD до repair — `298da2b`. Task source — `/home/vostrikov/projects/packages/swissknifeman/packages/task`, directory marketplace; route/identity не подменялись. Подготовка выполнена через его `scripts/plan-work.py prepare --repeat-admission`. Чужие GROK_SESSION_ID/GROK_THREAD_ID/CLAUDE_SESSION_ID удалены только из capture subprocess; CODEX_THREAD_ID сохранён. Новый grant построен `task.plan_repeat.build()`, marker D11 вычислен `task.plan_repeat.digest()`, validate OK, draft root-session не consumed.

## Исправление и границы

- Общий `PanelCatalog::bind()` для любого ProvidesPolicies выбирает единственный public nonstatic метод с подходящим `#[Decides]`. Enum case сравнивается по identity с другим case, case и локальная строка — по строковому значению. Explicit method должен совпасть; отсутствие, неверное действие, неоднозначность, nonpublic/static и посторонний explicit method дают DefinitionException. Повтор атрибута на одном методе не превращает один метод в два.
- Найденное имя всегда записывается в `binding_methods`. Дублирующий `FolderSource::requireMethod()` удалён; discovery/pairing остаётся своим механизмом. Сигнатуры, DI runtime inputs и вызов политики не проверялись и остаются P4.3.
- `tests/Fixtures/Permissions/ClientPolicy.php` сохранён пустым и без diff. Третий точный R1 probe использует именно его. Позитивные catalog/role/cache/collision fixtures переведены на отдельный AttributedClientPolicy.
- `tests/Feature/Sources/Folder/PolicyBindingTest.php`: три точных R1 probes из review, правильный explicit method для folder/custom, custom normalization по case/имени, отказ invalid bindings для обоих источников, настоящий cache round trip через `azguard:catalog:cache` и новый boot. Cached catalog — другой объект с равным snapshot, сохранёнными именами и нулём чтений источников.

## Validation evidence

Все команды с APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:. TestCase задаёт testbench SQLite :memory: до тестов. Cache-файлы — уникальные каталоги `/tmp/azguard-binding-*` с cleanup. Product hashes, environment и exit codes: `artifacts/P2-repairs/P2.8-gates.json`; полные логи рядом.

| Carrier | Результат |
|:--|:--|
| Focused regression до repair | RED: 24 tests, 8 passed / 16 failed, включая три точных probes (`P2.8-focused-red.log`). |
| Focused regression после repair | GREEN: 24 passed, 34 assertions (`P2.8-focused.log`). |
| Targeted folder/catalog | GREEN: 107 passed, 325 assertions, exit 0 (`P2.8-targeted.log`). |
| Arch | GREEN: 63 passed, 291 assertions, exit 0 при `php -d memory_limit=1G vendor/bin/pest tests/Arch` (`P2.8-arch.log`). Первый literal carrier при стандартных 128 MiB завершился OOM/exit 255; `P2.8-arch-first.log` сохранён, не считается GREEN. |
| API | `composer api:manifest` выполнен штатным генератором; manifest bytes не изменились. `php bin/api-manifest.php --check`: exit 0. |
| Full `composer test` | GREEN: 1312 passed, 239492 assertions, exit 0 (`P2.8-full.log`), без skipped tests. |
| Pint | GREEN: `vendor/bin/pint --test`, exit 0. |
| PHPStan | GREEN: exit 0, 0 errors. Startup warning turbo extension `Dynamic loading not supported` сохранён в `P2.8-phpstan.log`; это дефект окружения, не скрыт. |
| Type coverage | GREEN: 99.8%, все 146/146 src files, exit 0, без warnings на повторе. Первый exit 0 содержал warning foreach(null) в vendor plugin Analyser.php:140 и неполный вывод; `P2.8-types-first.log` не использован как финальное доказательство. Повтор содержит PanelCatalog и FolderSource 100%. |
| Diff | `git diff --check`: exit 0. |

## Plan/runtime observations

На хосте отсутствует alias `python`: использован python3. При прямом импорте task требуется repo PYTHONPATH для shared core; штатный plan-work сам добавляет этот путь. Runtime source не менялся.

Plan-lint относительно HEAD до terminal: 13 errors, 1 new. 12 прежних ошибок P0/P1 — отсутствие write-site в исторических Completion Notes. Единственная новая — journal line 43, исторический terminal P2.5 с прежним spec hash после разрешённого owner/root уточнения спецификации перед repeat. Это подготовленный sibling carrier; P2.5 в данном run не запущен и не переписан. Полный lint не объявляется GREEN. Следующий owning repeat P2.5 должен восстановить актуальный terminal evidence. Штатный finalize проверил state/decision/status projections и terminal receipt P2.8. Первый finalize остановился на неканоническом заголовке handoff, повтор тоже отклонён из-за лишнего суффикса; заголовок исправлен точно на `# HANDOFF — 2026-10-03 — after P2.8`, затем resumable finalize завершился exit 0. Эти отказы не объявлялись GREEN. Runtime сохраняет `next_bundle: bundles/P3.1.json` (пустой skeleton уже существовал), но receipt имеет `manual: frontier/high`; это не start P3. Phase closure P2 отсутствует.

## Продолжение

**Next manual: frontier/high** — выполнить только owning repeat P2.5 для R2/R3 с отдельным настоящим grant, построенным после terminal P2.8. P2 RED; P3 и GREEN closure P2 не разрешены этим результатом. R4 уже синхронизирован root в P4 до start. Push запрещён. Чужие .gitignore, .swissknife.json и .grok/ сохранены вне scoped commit. Спецификации после start не изменялись.
