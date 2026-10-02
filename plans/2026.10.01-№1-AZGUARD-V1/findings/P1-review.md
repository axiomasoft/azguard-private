# P1 review — независимая read-only проверка фазы

**Plan:** `2026.10.01-№1-AZGUARD-V1` · **Item:** P1.7 · **Run:** `e6913f5af4e479464d9d0e1e4873911a83f5fe2ba3f76f6eb0d0164c18525b40`
(session `90dc065a-f255-4699-81af-e9d388c36233`, claude-opus-5-5, frontier/high) · **Date:** 2026-10-02
**Diff:** `git diff 9bb02fc..78cade7 -- packages tests bin composer.json` (92 файла, +8038/−3).

## Verdict: RED

Один major: блокирующее arch-правило baseline-набора доказанно не может покраснеть. Остальные проверки (1)–(8) пройдены;
два minor не блокируют. Фаза P1 закрывается после исправления F1 в owning item.

## Находки

| # | Severity | Файл:строка | Нарушенный источник | Owning item |
|:--|:--|:--|:--|:--|
| F1 | major | `tests/Arch/BaselineArchTest.php:10` | 04 §2 «Arch-правила (Pest arch, блокирующие в CI)»; Acceptance P1 «arch-набор … доказанно краснеет на нарушении»; Implementation Rules P1.7 «пропуск гейта ≠ GREEN» | P1.4 (расширение Files на `tests/Arch/BaselineArchTest.php`) |
| F2 | minor | `packages/core/src/Kernel/Decision/Decision.php:91`, `Grant.php:52,74,108`, `DecisionSet.php:46`, `StateToken.php:40,44`, `CodeStateToken.php:31`, `RestrictionResult.php:29` | D37 «у каждой ошибки стабильный машинный код», база `AzGuardException` | P1.3 |
| F3 | minor | `phases/P2/P2.md:9`; источник отсрочки — `findings/P1-execution.md:238-240` | D6 Consequences (перенос отражается в Phase Context скелета принимающей фазы) — применено к собственной отсрочке P1.6 | P1.6 |

### F1 — правило «no debug calls» вакуумно

`arch('package sources contain no debug calls')->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])->not->toBeUsedIn('AzGuard')`.

- **Механизм (vendor-источник):** `OppositeExpectation::toBeUsedIn` (`vendor/pestphp/pest/src/Expectations/OppositeExpectation.php:709-717`)
  строит `ToBeUsedIn::make($original, 'AzGuard')` → `ToUse::make(expect('AzGuard'), [dd, dump, ray, var_dump, print_r])`
  (`vendor/pestphp/pest-plugin-arch/src/Expectations/ToBeUsedIn.php`) и берёт отрицание. Положительное ожидание —
  «AzGuard использует **все** пять функций»; отрицание проходит, как только хоть одна не используется, т.е. всегда.
  Это вторая причина помимо разрешения неквалифицированного вызова, записанной в `P1-execution.md:164-173`.
- **Воспроизведение (scratch-копия, рабочее дерево не тронуто):** `dump(1);` в `Kernel\Identity\SubjectRef::key()` →
  `vendor/bin/pest tests/Arch`: правило `package sources contain no debug calls` GREEN (упало только правило Kernel из-за
  одновременно внедрённого `Illuminate`, см. «Повтор доказательства RED»).
- **Последствие:** `dd()`/`dump()` в `src` проходит блокирующий CI.
- **Направление исправления (без обходов):** заменить правило token-сканом по всем `packages/*/src`, обобщив
  существующий `frameworkHelperCalls()` из `tests/Arch/ZonesArchTest.php` до одного помощника со списком функций
  (одна реализация для Kernel-хелперов и debug-вызовов, не копия), с самопроверкой на синтетическом коде и
  доказательством RED. Файл вне Files P1.4 — нужна прозрачная поправка контракта P1.4 владельцем плана.

### F2 — инварианты значений бросают SPL `InvalidArgumentException`

Нарушения инвариантов `Decision` (effect/reason), `Grant` (роль другой панели, поля не plain data), `DecisionSet`
(разные токены), токенов состояния и `RestrictionResult::deny` бросают `InvalidArgumentException` без `code()` и вне
иерархии `AzGuardException`.

- **Сценарий:** приложение оборачивает пользовательский источник в `catch (AzGuardException $e)` и логирует `$e->code()`;
  `Grant::of(..., fields: ['model' => $user])` из этого источника выходит мимо catch.
- **Смягчение:** решение записано как «ошибка программиста» (`P1-execution.md:103`); во время проверки сбой источника
  или ограничения станет отказом `source_error`/`restriction_error` (05 §10), т.е. fail-closed сохраняется.
- **Исправление требует имени:** в таблице 05 §10 нет ветки для нарушения инварианта значения; новое имя — только
  решением владельца (имена из `03`/D-решений). Не блокирует закрытие фазы.

### F3 — отсрочка P1.6 не отражена в скелете P2

P1.6 перенёс в компиляцию панели (P2.5/P2.8) проверку переопределённых `BaseRole::key()`/`formerKeys()` и дубликатов
ключей ролей (`P1-execution.md:238-240`, `packages/core/src/Roles/BaseRole.php:40,76` проверяют только значения
атрибутов). В отличие от переносов D6, это не записано в Phase Context `phases/P2/P2.md`; P2.5 и P2.8 — пустые скелеты.

- **Сценарий:** при детализации P2.8 по строке 13 досье (роли с `#[Role]`) проверка переопределений и коллизий
  `formerKeys` между ролями не попадает в Scope; роль с `formerKeys()` чужого текущего ключа проходит.
  Невалидный по грамматике ключ всё равно отклонит `RoleKey::of` (fail-closed), поэтому severity minor.
- **Исправление:** строка «Принимает от P1.6» в Phase Context `phases/P2/P2.md` (план, не код).

## Проверки Scope

| # | Проверка | Результат |
|:--|:--|:--|
| 1 | Склейка строк только в `key()`/`full()`; tagged-кодирование | ✓ поиск `.':'.`/`implode`/`sprintf` по `src`: только `key()`/`full()` ссылок и ключей; `Grant.php:101` — путь в сообщении. `encode()` различает `permission/pattern/role/subject/tenant/context/scope/actor`; `compose()` — JSON с версией кодека; type alias запрещает `:` (D07) |
| 2 | Грамматика = D18/D06; голые `*`/`**` | ✓ сегмент, ≥ 2 сегментов, ≤ 255, `panel:local`, wildcard только последним; id панели и ключ роли `^[a-z0-9][a-z0-9-]{0,63}$` (D06, `02:393`). Все фабрики (`PermissionKey::of/parse`, `PermissionPattern::of`, `RoleKey::of/parse`, `IdentityCodec::decode`) идут через грамматику; приватные конструкторы |
| 3 | Effect/reason и fail-closed `Decision` | ✓ `admits()`: Allow — `granted/super_admin/policy`; NotApplicable — только своя причина; Deny — без `granted/super_admin/not_applicable`. `allowed()` истинно только для Allow; конструктор приватный; deny не несёт grants |
| 4 | Таблица 19 §2 | ✓ `PermissionAuthority::allows`: Policy — `policy === true`, contribution игнорируется; Grants — `qualified && policy !== false`. Кейсы `Policy/Grants` = 05:815; superadmin authority только в Grants (D19, D83) |
| 5 | Kernel/Exceptions без Laravel; arch краснеет; манифест детерминирован | ✓ arch и token-скан Kernel; повтор доказательства RED (ниже). Генератор: `ksort` классов/методов/свойств, сортировка интерфейсов, кейсы enum в порядке объявления, параметры в порядке сигнатуры; `--check` exit 0. Дефект baseline-правила — F1 |
| 6 | Имена из `03`/D6; нет кодов задач в `src` | ✓ каждое короткое имя класса `src` встречается в досье; методы сверх перечня 05 записаны в контрактах P1.1–P1.6. `ActorRef::$type: string` — сужение, записанное в P1.1. Коды задач: тест GREEN, ручной поиск `D\d`/`P\d`/`V\d` по комментариям — 0 |
| 7 | P07/P14 проверяют обратное ожидание | ✓ P07: отказ type `workspace:a` (вектор склейки 0.3) и различие digest кортежей, совпадающих при `implode(':')`; мутация из P1.1 делала тест RED. P14: `*`/`**` отклонены во всех точках входа грамматики и в `PatternMatcher` |
| 8 | Перенос D6 в скелетах P2/P4/P5 | ✓ `P2.md:9`, `P3.md:9`, `P4.md:9`, `P5.md:9` перечисляют все значения D6 с owning items. Собственная отсрочка P1.6 — F3 |

SPI `Contracts\{Scopes,Subjects}` и `ResolvedAssignmentScope` совпадают с 06 §7 посигнатурно; `AssignmentScopePhase`
— кейсы `18-contexts-and-runtime-inputs.md:161`; исключения и коды — 05 §10 и D6 п.4.

## Повтор доказательства RED (Code Guidance (5))

Копия `packages tests bin composer.json phpunit.xml` в scratchpad, `vendor` — жёсткие ссылки; symlink
`vendor/axiomasoft/*` относительные, автозагрузка указывает на копию.

| Шаг | Результат |
|:--|:--|
| исходная копия, `vendor/bin/pest tests/Arch` | GREEN: 51 passed |
| доказательство 1 из P1.4 (`\Illuminate\Support\Str` в `SubjectRef::key()`) + `dump(1)` | RED: 1 failed — `kernel depends on nothing but PHP` («Expecting 'AzGuard\Kernel' not to use 'Illuminate'»); debug-правило GREEN → F1 |
| файл восстановлен из рабочего дерева | GREEN: 51 passed |

## Гейты

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `composer test` | GREEN: 505 passed, 237 286 assertions |
| 2 | `vendor/bin/pint --test` | GREEN |
| 3 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors (warning загрузки `phpstan_turbo-8.4.so` — окружение, на результат не влияет) |
| 4 | `php bin/api-manifest.php --check` | exit 0 |
| 5 | `vendor/bin/pest --type-coverage --min=98` | GREEN: 100.0 %, 58/58 файлов `packages/*/src`, 0 `Warning:` |
| 6 | `git status --porcelain -- packages tests bin` | пусто |

## Закрытие фазы и граница владельца

- P1.7 как пункт выполнен: deliverable записан, гейты 1–6 GREEN; terminal `closed-green` в `journal.jsonl` относится
  к пункту, а не к фазе. Acceptance P1 («verdict GREEN или все находки исправлены») **не выполнен** — фаза не закрыта.
- `plan-work.py finalize --item P1.7` → `PLAN_RECONCILIATION_INCOMPLETE: PHASE_CLOSURE_REQUIRED`: редьюсер видит все
  пункты P1 Done и требует закрыть фазу. Закрытие не выполнено намеренно.
- `[POSSIBLE-DEFECT]` Task runtime: `plan_gates.phase_close_gate` блокирует закрытие только по событию `plan-audit`
  (`audit-red` + `return-owning-continuation`), а запись `plan-audit` требует `audit-admission/v2`. RED-verdict пункта
  `Review Pn` (D3) не потребляется гейтом — нет пути записать блок без согласия на аудит. Исправление — в генераторе/
  редьюсере пакета `task` (swissknifeman), не в этом плане.
- Ремонт F1 требует решения владельца: повторный допуск терминального P1.4 (`--repeat-admission`, маркер
  `[REPEAT-ADMISSION:<digest>]`) и прозрачная поправка Files P1.4 на `tests/Arch/BaselineArchTest.php`; после GREEN
  P1.4 — повторная проверка только F1. F2/F3 — по решению владельца (не блокируют).

## Наблюдения вне владения P1

- `[POSSIBLE-DEFECT]` `pest --type-coverage` молча пропускает файлы при ошибке PHPStan (`P1-execution.md:263-267`).
  В этом прогоне проанализированы все 58 файлов, предупреждений нет — гейт 5 доверен вместе с GREEN PHPStan.
  Жёсткая проверка числа файлов/`Warning:` — обёртка гейтов D4, не код P1; место — гейты P8 или `roadmap/`.

## Повторная проверка (P1.8)

**Item:** P1.8 · **Run:** `d679eb44517403df698528f513978448d8a9a211c04702ae02ebcc88489f019b`
(session `8689e1a5-2a99-4483-9e71-9d4240ec1a58`, claude-opus-5-5, frontier/high) · **Date:** 2026-10-02 · решение [D7](../decisions/D7-p1-review-repair.md).
Проверяются только F1–F3 (D3) и гейты D4.

### Verdict фазы: GREEN

| # | Исправление | Результат |
|:--|:--|:--|
| F1 | `tests/Arch/SourceScan.php` — одна реализация: `files()` (PHP-файлы `packages/*/src` или каталога), `functionCalls()` (вызовы глобальных функций по токенам; методы, `::`, объявления, `new`, комментарии и строки — не вызовы), `callsIn()`. `frameworkHelperCalls()`/`sourceFiles()` удалены из `ZonesArchTest`/`SourceConventionsTest`, самопроверка хелперов идёт через `SourceScan`. Правило «no debug calls» в `BaselineArchTest` — token-скан всех `packages/*/src` по `dd`, `dump`, `ray`, `var_dump`, `print_r` с самопроверкой на синтетическом коде (регистр, `\var_dump`, `$x->dump()`, `?->ray()`, `Debug::dd()`, `new Ray()`, `function ray()`, комментарий, строка) | ✓ закрыта |
| F2 | `ConsistencyException` (`consistency`) и `InvalidSourceContributionException` (`invalid_source_contribution`) — `final` от `AuthorizationEngineException`, без конструкторов. `Decision`, `DecisionSet` → `ConsistencyException`; `Grant` (роль другой панели, `assertFields`), `RoleContribution`, `RestrictionResult` → `InvalidSourceContributionException`; `CodeStateToken`, `StateToken` → `InvalidIdentityException`. Сообщения не изменены; `@throws` обновлены; в `packages/*/src` нет `InvalidArgumentException`. Тесты значений проверяют класс и сообщение; иерархия — родитель, код, `final`. Манифест ядра: +2 класса через `--write` | ✓ закрыта |
| F3 | Phase Context `phases/P2/P2.md`: строка «Принимает от P1.6» — проверка переопределённых `BaseRole::key()`/`formerKeys()`, дубликатов и коллизий `formerKeys` при компиляции панели (P2.5/P2.8) | ✓ закрыта |

### Доказательство RED (Code Guidance P1.8)

Копия `packages tests bin composer.json phpunit.xml` в scratchpad, `vendor` — жёсткие ссылки, `vendor/axiomasoft/*` —
относительные symlink на копию; каждое внедрение — от файла рабочего дерева.

| Шаг | `vendor/bin/pest tests/Arch` |
|:--|:--|
| исходная копия | GREEN: 52 passed |
| `dump(1);` в `Kernel\Identity\SubjectRef::key()` | RED: 1 failed — `keeps package sources free of debug calls` (`packages/core/src/Kernel/Identity/SubjectRef.php: dump()`) |
| `now();` там же (вместо `dump`) | RED: 1 failed — `keeps the kernel free of framework helper calls` |
| файл восстановлен (`cmp` с рабочим деревом) | GREEN: 52 passed |

### Гейты

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `vendor/bin/pest tests/Arch tests/Unit/Kernel/Decision tests/Unit/Exceptions` | GREEN: 178 passed, 529 assertions |
| 2 | доказательства RED на scratch-копии | RED → RED → GREEN (таблица выше) |
| 3 | `composer test` | GREEN: 510 passed, 237 298 assertions |
| 4 | `vendor/bin/pint --test` | GREEN |
| 5 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors (warning загрузки `phpstan_turbo-8.4.so` — окружение) |
| 6 | `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN: 100.0 %, 60/60 файлов `packages/*/src`, 0 `Warning:` — см. отклонение ниже |
| 7 | `php bin/api-manifest.php --check` | exit 0 |
| 8 | `git diff --check` | exit 0 |

**Отклонение гейта 6 (прозрачно):** буквальный вызов `vendor/bin/pest --type-coverage --min=98` при `memory_limit=128M`
CLI по умолчанию напечатал 8 `Warning: foreach() argument must be of type array|object, null given`
(`pest-plugin-type-coverage/src/Analyser.php:140`) и молча пропустил 9 изменённых файлов (форк-воркер не вернул
результат), сохранив exit 0 и 100 %. С `-d memory_limit=1G` — тем же лимитом, что у `composer test` — проанализированы
все 60 файлов без предупреждений. Критерий carrier (0 `Warning:`, число файлов = числу PHP-файлов `src`) выполнен только
во втором вызове.

- `[POSSIBLE-DEFECT]` `composer test:types` (`composer.json:73`) не задаёт `memory_limit`, поэтому гейт типового покрытия
  на холодном кэше молча теряет файлы — это то же наблюдение «вне владения P1», теперь с воспроизведённой причиной.
  Исправление — обёртка гейтов D4 (P8) или `roadmap/`; вне Files P1.8.

Acceptance P1 выполнен: verdict review RED, все находки F1–F3 исправлены и проверены; фаза P1 закрывается.
