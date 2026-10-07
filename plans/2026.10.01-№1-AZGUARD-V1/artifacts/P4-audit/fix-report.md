# Исправление находок аудита P4 — итог

База: HEAD `ee63438` (аудит `report.md`). Исправления: 5 коммитов поверх него, ветка `main`, без push, amend и `--no-verify`.

## 1. Находки

| ID | Статус | Тест | Коммит |
|---|---|---|---|
| A01 (blocker) | исправлено | `tests/Feature/Authorization/ScopedLifecycleTest.php` (decide, explain, decideMany, Gate, resolve через `forgetScopedInstances()` без `forgetInstance(Authorizer)`; граф singleton-ов не достигает scoped-сервисов) | `1e99491` |
| A06 (blocker) | исправлено | `tests/Feature/Visibility/HostRawOrTest.php` (SQL-форма, привязки, паритет со скалярным `decide()`, гость, дескриптор области с raw OR), `PredicateCompilerTest.php` (две новые проверки) | `343298a` |
| A02 (major) | исправлено | `tests/Feature/Authorization/GrantRoleSuperAdminTest.php`, `tests/Feature/Visibility/GrantRoleSuperAdminVisibilityTest.php` | `bfa0d45` |
| A03 (major) | исправлено (обе части: внешний адаптер и нативный фильтр) | `tests/Feature/Authorization/Batch/DecideManyContributionKeyTest.php` (внешний ключ в обоих порядках грантов; нативный вердикт tenant-wide и контекстного вклада одной роли) | `f02ec10` |
| A07 (minor) | исправлено | `tests/Feature/Authorization/Batch/DecideManyDefinitionErrorTest.php` | `f02ec10` |
| A08 (minor) | исправлено (воспроизведено исполнением, не только чтением кода) | `tests/Feature/Authorization/DynamicSourceConstructionErrorTest.php` | `c8739a9` |
| A09 (minor) | тесты добавлены; мутанты M11 и M12 теперь убиваются | `tests/Feature/Visibility/GlobalRolesGuardTest.php`, `RoleScopeGuardTest.php` | `6a72fb4` |
| A04 (minor) | нужно решение владельца, не чинилось | — | — |
| A05 (minor) | нужно решение владельца, не чинилось | воспроизведение осталось только в `repro/VisibilityAppliesToTest.php` (падает намеренно, в `tests/` не переносилось) | — |

Для каждой исправленной находки проверено красное состояние: тест падает без исправления (временный возврат файла) и зелёный с ним. Для A09 (пробел в тестах) тесты проходят на HEAD; мутанты применены вручную: без проверки global-role падает `GlobalRolesGuardTest`, без проверки role-scope падает `RoleScopeGuardTest`.

Что именно изменено:

- A01. `Authorizer` зарегистрирован как `scoped` (`AzGuardServiceProvider.php`). Весь граф стадий (`BoundaryStage`, `PanelResolver`, `PrepareStage`) пересоздаётся вместе с `CurrentContext`/`CurrentPanel`. Публичные сигнатуры не менялись.
- A06. `PredicateCompiler::constrain` переносит WHERE хоста в одну вложенную группу, если в нём есть raw-условие или `Expression`. Остальные запросы не меняются (тесты на SQL-форму сгруппированного хоста прежние). Через `constrain` проходят `visibleTo()`, гостевой `deny` и запрос дескриптора области (`VisibilityScope`), поэтому правка покрывает все три пути. В фикстуру `VisibilityWorld` добавлен хук `$projectQuery` для теста дескриптора.
- A02. `$admin = $item instanceof RoleContribution && …` в `AuthorityStage` и в `Visibility`.
- A03. Ключи `BatchInputs::external()` и `eligibilityKey()` строятся из полной идентичности вклада (`contributionParts()`: класс, роль, область вклада, источник, происхождение, паттерн, срок с микросекундами, поля) через `IdentityCodec`, без `json_encode` value-объектов.
- A07. `BatchEvaluation::group()` пробрасывает `DefinitionException`, `UnknownPermissionException` и `InvalidConfigurationException` (кроме `authority_transaction`); остальное по-прежнему деградирует в `source_error`. `SubjectNotAcceptedException` уже наследует `DefinitionException`.
- A08. Построение попытки чтения в динамической ветке `PrepareStage::complete()` обёрнуто так же, как статическая ветка: `Deny(SourceError, 'dynamic_sources')`.

## 2. Проверки

| # | Проверка | Exit |
|---|---|---|
| 1 | `vendor/bin/pint --test` | 0 |
| 2 | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | 0 (первый прогон дал 1 из-за моего лишнего `instanceof` в `BatchEvaluation`; исправлено до коммитов, итоговый 0) |
| 3 | `composer test:types` | 0 (99.4 %) |
| 4 | `php bin/api-manifest.php --check` | 0 (публичный API не менялся, `composer api:manifest` не запускался) |
| 5 | полный `composer test` в `serversideup/php:8.3-cli` с redis | 0 (3023 passed, 1 skipped, 404971 assertions) |
| 6 | engines: PostgreSQL 16.15 / MySQL 8.4.11 / MariaDB 10.11.19 (`--group=engines --fail-on-skipped`) | 0 / 0 / 0 (по 45 passed) |
| 7 | `git diff --check` | 0 |

Оговорка по п. 5: единственный пропуск — `tests/Engines/ReplicaLagTest.php` (V46/R52), он требует профиль authority-replica (`bash tests/Engines/Support/replica-fixture.sh`). Он был пропущен и в базовом прогоне аудита и не связан с этими правками. Это не пройденная проверка: поведение на реплике этой работой не подтверждено.

## 3. Вопросы владельцу

### A04 — политика вызывается раньше `NotGranted`

Сейчас в режиме Grants без квалифицирующего права host-политика всё равно вызывается: `false` даёт `Deny(Policy)`, исключение — `PolicyError`, оба раньше `Deny(NotGranted)`. Это закреплено тестом `tests/Acceptance/Crm/PoliciesTest.php:34` (R22), поэтому ломать его без решения нельзя. Wrong Allow невозможен.

- Вариант 1 (рекомендую, соответствует 09 §2 шаг 3b): при `qualified === false` возвращать `NotGranted` до вызова политики. Нужно обновить R22 и ожидание в `PoliciesTest.php:34`, добавить spy «политика не вызывается». Эффект для пользователей: другая причина отказа и политика не выполняется на каждой неавторизованной проверке.
- Вариант 2: оставить порядок и записать отклонение от 09 §2 в решения плана (текст P4.3 допускает оба прочтения). Тогда host-код политики должен быть безопасен для субъектов без права.

### A05 — `appliesTo` ограничения, зависящий от ресурса, в списках

Точная видимость вызывает `appliesTo()` с запросом без ресурса и пропускает предикат, если вернулось `false`; скалярный путь вызывает его с конкретным ресурсом. Воспроизведено: скаляр `[101]`, список `[101, 102]` (список шире скаляра, то есть показывает лишние строки). Правка меняет контракт D15 §6 / P14, поэтому не делалась.

- Вариант 1: всегда компилировать предикат ограничения и считать, что применимость кодируется внутри `predicate()` (например, `P::pass()` для неприменимых). Простое правило, закрывает дыру; требует обновить документацию SPI `FiltersAccessQueries` и добавить кейс в `ParityPropertyTest`.
- Вариант 2: объявить, что `appliesTo()` не должен зависеть от ресурса, и это проверять. Статически проверить нельзя; можно вызывать `appliesTo()` на запросе с фиктивным ресурсом и бросать `VisibilityNotSupportedException`, но это ложные срабатывания и новая ошибка на этапе вызова.
- Вариант 3 (самый консервативный): при `appliesTo() === false` в списке не пропускать предикат, а падать закрыто (`VisibilityNotSupportedException`), пока владелец не выберет 1 или 2.

### Прочее, что не делалось или остаётся

- Документация: в `docs/advanced/context.md` (и `docs/ru/…`) осталось описание `AuthorizationContextManager` как singleton; оно не соответствует коду и к находкам не относится, не трогал.
- A06: после `visibleTo()` нельзя добавлять верхнеуровневый `orWhere` (прежняя проверка `PredicateCompiler` бросает `DefinitionException` для `or` в начале условий; это сохранено). Записано в CHANGELOG.
- A01: `ChangePipeline` (P5.2) хранит `ActingActor` (scoped), но пока нигде не удерживается singleton-ом. Добавленная проверка графа singleton-ов (`ScopedLifecycleTest`) сработает, если это изменится и класс попадёт в граф проверяемых singleton-ов; для самого `ChangePipeline` отдельного теста нет. Реальный Octane/очередь по-прежнему только имитируются `forgetScopedInstances()`.
- A03: исходное описание аудита («нативный ключ без области вклада») воспроизводится только при сериализуемой конфигурации фильтра; с замыканием ключи различаются идентификатором `closure:N` и коллизии нет. Тест использует класс-фильтр.
- Не запускалось: ReplicaLag с профилем реплики, `bin/coverage-gate.sh`, Infection, Rector dry-run (как и в аудите).

## 4. Итог после решений владельца

- A04 → решение D21, коммит `b8206d5` (`fix(p4): не вызывать политику Grants до квалификации вклада`); запушен.
- A05 → решение D22, коммит `6187ebe` (`fix(p4): компилировать predicate адаптерных ограничений в списках всегда`); запушен.
- Severity A05 владельцем не решалась; в отчёте аудита предложена medium.
