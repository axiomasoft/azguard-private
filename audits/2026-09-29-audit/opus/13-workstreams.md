# 13 — Рабочие потоки: фазы и пункты для плана

Нумерация `Pn.m` стабильна: на неё ссылаются сценарии [14-verification.md](14-verification.md). План создаётся по
протоколу проекта (`plans/ACTIVE.md`); фазы и пункты переносятся **без перенумерации**.

Коротко, в каком порядке: сначала закрываем опасные дефекты в текущей версии (F1). Затем строим основу: значения
ядра (F2), панели и плагины (F3), хранилища (F4). На этой основе — проверка прав (F5) и изменение прав (F6). В конце —
Laravel-поверхность, Filament, интеграции и релиз (F7–F9).

## 0. Правила исполнения (переносятся в `Execution Rules` плана)

1. **Сначала T0-патч 0.3.x (F1), потом канон 0.4 (F2–F9).** T0 не ждёт канона: это безопасность текущих пользователей.
2. Каждый пункт с probe начинается с **перевода probe** из [evidence](evidence/README.md) в регрессионный тест с
   **обратным** ожиданием (красный → зелёный). Probe без инверсии = пункт не закрыт.
3. Исполнитель читает только указанные разделы досье; расхождение досье и кода → вопрос в `open-questions.md`, не
   импровизация.
4. Пункты с маршрутизацией **opus** не отдаются младшим моделям без готовой спецификации (`findings/Pn.m.md`).
5. После каждого пункта: `composer test`, Pint, PHPStan (уровень проекта), arch-тесты; падение или пропуск ≠ успех.
6. **Встроенные плагины пишутся только через публичные разъёмы** (arch-тест из [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему)).
   Если встроенному плагину не хватает разъёма — сначала добавляется разъём, потом плагин.
7. Переименования — одним механическим проходом по [03](03-glossary-and-renames.md) после F3 (когда новые типы
   существуют), с FQN-якорями; старый код удаляется в том же пункте, без временных алиасов (D01).
8. Изменения схемы — только через новые миграции; fresh и upgrade тестируются раздельно (D35).
9. Код Vaulter и других пакетов из этого плана **не** меняется. Заметки по мосту Vaulter
   ([10 §10](10-integrations.md#10-заметки-для-vaulter-по-текущему-мосту)) передаются в план Vaulter отдельной задачей.

## F0 — Подготовка

| Пункт | Что | Готово когда | Модель |
|---|---|---|---|
| P0.1 | ADR «Ecosystem conventions» (текст из [10 §8.1](10-integrations.md#81-общие-инженерные-правила-adr-ecosystem-conventions-один-текст-в-репозиториях-экосистемы)) в `docs/adr/` | ADR в репозитории; предложение передано владельцам других пакетов | sonnet |
| P0.2 | Перенести probes в `tests/Audit/` как `todo`-набор (не в основной прогон) | `vendor/bin/pest tests/Audit` воспроизводит 19/19 | sonnet |
| P0.3 | Каркас consumer-фикстуры (чистый Laravel + собранные архивы) в CI (job без блокировки) | job зелёный на 0.3 | sonnet |

## F1 — T0-патч 0.3.x (выпуск 0.3.N)

Минимальные исправления в **текущей** архитектуре — без переделки.

| Пункт | Что | Решение / находка | Готово когда | Модель |
|---|---|---|---|---|
| P1.1 | `ClassRoleGrantSource`: `*` class-роли — только в панели роли (панель из `panel:name`; `super-admin` — глобально, как сейчас); исправить docs super-admin | D19 / N01, P01a | P01a инвертирован; P01b без изменений | opus |
| P1.2 | `hasPermission()`/`permissionSet()`: ключ с префиксом зарегистрированной панели оценивается по **этой** панели, если `$panelId` не передан | D05 / N01, N09, P01c, P09 | P01c, P09 (часть `hasPermission`) инвертированы | opus |
| P1.3 | `Authorizer` зависит от `PermissionResolverInterface`; `explain()` получает источники через отдельный internal-контракт | D48 / N03, P03 | P03 инвертирован | sonnet |
| P1.4 | `GrantBuilder`, `HasDirectGrants::grant`, `guard:grant` отвергают `*` (опция `--superadmin` для CLI) | D19 / N13, P14 | P14 инвертирован | sonnet |
| P1.5 | Filament: `class_name` только для чтения; Create/Edit его не пишут; ресурсы AzGuard требуют мета-права `{panel}.azguard.roles.manage` / `…grants.manage`; выдача без эскалации | D23, D30 / N02 | V20–V23 (облегчённые) зелёные | opus |
| P1.6 | `Role::getRoleLogic()` на пути чтения → `null` + warning (исключение — только в sync/doctor) | D14 / N12, P02 | P02 инвертирован | sonnet |
| P1.7 | `Role`, `RolePermission` — `RevisionedPermissionModelWrites`; удаление роли вне Filament двигает ревизию | D22 / C04 | тест «удаление роли инвалидирует кэш» | sonnet |
| P1.8 | Контекст: дискриминатор `json_encode([$type, (string) $id])`; `AuthorizationContext` отвергает `:` в типе; `equals()` по канонической строке | D07 / C01, P07 | P07 инвертирован | sonnet |
| P1.9 | `ContextGuard::checkInContext()`: `set()` и `forgetRequestCache()` внутри `try` | D16 / C02 | probe Codex инвертирован | sonnet |
| P1.10 | `hasPermission(..., $context)` без context-пакета → `false` + warning (как `hasPermissionIn`) | D16 / N06, P06b | P06b инвертирован | sonnet |
| P1.11 | `merge_strategy` принимает карту `panel => strategy` (скаляр — общий fallback) | D15 / N07, P06 | P06 инвертирован при карте | sonnet |
| P1.12 | `bootHasScopedRoles`: без пользователя → `on_missing_user` (по умолчанию `empty`); пустые назначения → пусто; строки назначений объединяются через `orWhere`-группу; документировать границы | D31 / N04, P04 | P04a–c инвертированы | opus |
| P1.13 | `PermissionStateRevision::current()` и встроенные источники — `useWritePdo()` | D24 / C03 | тест SQL-маршрутизации (read/write соединения) | sonnet |
| P1.14 | `InstallCommand`: пропагировать код `migrate`; `default: false` | D40 / C10 | тесты installer | sonnet |
| P1.15 | Выпуск 0.3.N: CHANGELOG с классами изменений, раздел «Security» | D01 | тег, release notes | sonnet |

## F2 — Ядро: значения, грамматика, решение (0.4)

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P2.1 | `Kernel\Identity\*`: `PermissionKey`, `PermissionPattern`, `PanelId`, `RoleKey`, `SubjectRef`, `ContextRef`, `AnyContext`, `Actor`, `ActorRef`, `IdentityCodec` | [05 §2](05-php-api.md#2-значения-ядра-azguardkernel), D07, D18 | property-тесты инъективности и round-trip (V01–V04) | opus |
| P2.2 | `Kernel\Grammar\{PermissionGrammar, PatternMatcher}` | D18 | таблица грамматики как data-provider; легаси-грамматики нет | sonnet |
| P2.3 | `Kernel\Decision\*` (`AccessRequest`, `Decision`, `DecisionReason`, `Contribution`, `StateToken`, `Explanation`) + `PermissionSet` | 05 §2, 09 §1 | unit-тесты значений | sonnet |
| P2.4 | Arch-правила зон | [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему), D02, D12 | arch-набор зелёный на пустых зонах | sonnet |

## F3 — Панели, плагины, каталог, модули

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P3.1 | `PanelProvider`, `PanelBuilder`, `Panel`, `PanelRegistry` (freeze, duplicate, replace, `configure`), `CurrentPanel` | [05 §3](05-php-api.md#3-панели), D06 | V05–V07 | opus |
| P3.2 | Настройки панели: провайдер → плагины → конфиг, `PanelSettings` с источником каждого значения, инварианты не настраиваются, `azguard:panels:list --settings` | D45, [07](07-configuration.md) | V47 | opus |
| P3.3 | Система плагинов: `Plugin`, `DependsOnPlugins`, `BasePlugin`/`keyPrefix`, жизненный цикл register → проверка → заморозка → boot, конфликты, отпечаток политики, `--contributions` | [06 §1](06-extension-points.md#1-плагин), D47 | V48 | opus |
| P3.4 | Каталог: построители `enum/class/config`, `Ownership` O(1), коллизии (с учётом `keyPrefix`), `azguard:catalog:cache` | [06 §6](06-extension-points.md#6-каталог-и-роли-из-кода), D36 | V08, бюджет D44 для чужой ability | opus |
| P3.5 | Модули: `configurePanel()`, `registerPanel()`, enum с локальными ключами, `AmbiguousPanelException` | [06 §7](06-extension-points.md#7-модули-и-сторонние-пакеты-внутри-приложения), D50 | V55 | sonnet |

## F4 — Хранилища и данные

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P4.1 | `Storage`, `StorageRegistry`, `Storage::mutate(panel, …)` с ретраями и порядком блокировок, версия состояния **на панель**, `ids.host_keys` | [08 §1](08-data-model-and-migration.md#1-хранилища--простыми-словами), [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-ретраи), D24, D34 | запись без `mutate` невозможна для официальных путей; ретрай на deadlock (PG) | opus |
| P4.2 | Fresh-миграция 0.4 с префиксом хранилища на PG/MySQL/MariaDB/SQLite; `azguard:storage:migration` | [08 §2](08-data-model-and-migration.md#2-схема-хранилища-одинакова-для-любого-хранилища-p--префикс), D35 | DDL-снимки на 4 СУБД; два хранилища в одной БД не мешают друг другу | sonnet |
| P4.3 | Базовые модели (`Role`, `RolePermission`, `RoleAssignment`, `DirectGrant`), `GuardsDirectWrites`; свои модели панели, `azguardRules()`, `meta`, `decisionAttributes`; `azguard:make:models` | [08 §3](08-data-model-and-migration.md#3-свои-модели-и-колонки), [06 §4](06-extension-points.md#4-свои-модели-и-поля), D46 | V51; запись модели вне `mutate` → исключение в testing | opus |
| P4.4 | `azguard:upgrade` + upgrade-миграция + фикстура 0.3 со всеми случаями таблицы 08 §6 | [08 §6](08-data-model-and-migration.md#6-upgrade-03x--040) | V40–V42 зелёные на PG и MySQL | opus |
| P4.5 | `azguard:storage:move` | 08 §6, D46 | V52 | sonnet |

## F5 — Проверка прав: пайплайн доступа и встроенные плагины

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P5.1 | `AccessPipeline`: шаги подготовка → суперадмин → сбор → ограничения → наблюдение, правила шагов (что каждому шагу нельзя), `EvaluationContext` | [09 §1](09-authorization-semantics.md#1-пайплайн-доступа-алгоритм-decideaccessrequest-r), [06 §2](06-extension-points.md#2-пайплайн-доступа--разъёмы-шагов), D17, D48 | property-тесты P1–P8 (V15), V53 | opus |
| P5.2 | Плагин `azguard/roles`: `RoleDefinition`, `CodeRole`, `RoleGrantSource`, `RoleSynchronizer` (`formerKeys`, `--prune`) | 06 §6, D14 | V09–V11 | opus |
| P5.3 | Плагин `azguard/direct-grants`: `DirectGrantSource`; `GrantSource` с `Volatility` и `contextsCovering` | 06 §2, D13 | контрактный набор источников зелёный для встроенных | sonnet |
| P5.4 | Плагин `azguard/contexts`: `ContextPolicy`, `CurrentContext`, резолверы, middleware `azguard.context`, `withinContext`, ограничение членства | [09 §2](09-authorization-semantics.md#2-политика-контекстов-панели), D15, D16, D20 | V12–V14, V16 | opus |
| P5.5 | Плагин `azguard/superadmin` + `GlobalSuperadminPlugin` | [09 §3](09-authorization-semantics.md#3-суперадмин), D19 | V17 | opus |
| P5.6 | Кэш наборов прав (request + store), `StateToken` панели, отпечаток политики, `state_refresh` | [09 §6](09-authorization-semantics.md#6-кэш-и-консистентность), D24, D25 | V18–V19, V49, бюджеты D44 | opus |
| P5.7 | `decideMany()` | 09 §7, D27 | бюджет запросов D44 | opus |
| P5.8 | `explain()` + `azguard:explain` | 09 §9, D29 | V27 | sonnet |
| P5.9 | `GateBridge` (панель из ключа, режим и `superadmin_scope` — настройки панели, контекст из аргументов) | 09 §5, D26 | V28–V30 | opus |
| P5.10 | `Visibility` + трейт `ContextAware` | [09 §8](09-authorization-semantics.md#8-видимость-visibleto), D31 | V31–V33 | opus |
| P5.11 | `decisionAttributes` в вкладах и `matchingContributions()` для ограничений | 06 §4, D46 | V54 | sonnet |

## F6 — Изменение прав: пайплайн изменений

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P6.1 | `AccessManager` панели, `ChangePipeline` (полномочия → проверка → перехват → запись → след → уведомление), `ChangeResult` (`applied`/`pending`/`unchanged`), `MissingActorException` | [05 §7](05-php-api.md#7-accessmanager--единственный-вход-для-изменений), [06 §3](06-extension-points.md#3-пайплайн-изменений--разъёмы-шагов), D22, D49 | каждая операция: транзакция, новая версия, no-op без события | opus |
| P6.2 | Плагин `azguard/access`: мета-права, `DefaultDelegationPolicy`, `administeredBy` | D23 | V20–V22, V56 | opus |
| P6.3 | Отложенные изменения: `InterceptsChange`, `PendingChange`, `apply()`, событие `ChangePending` | 06 §3.1, D49 | V57 | opus |
| P6.4 | События после commit ([08 §7](08-data-model-and-migration.md#7-каталог-событий)); плагин `azguard/audit` (журнал в той же транзакции) | D28 | V34–V35 | sonnet |
| P6.5 | CLI: все пишущие команды через `manage()->asSystem()` с `--panel` | [12 §1](12-operations-and-release.md#1-команды) | снимок команд | sonnet |

## F7 — Laravel-поверхность и удаление старого

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P7.1 | Фасад, `PanelAuthorizer`, `SubjectAccess`, `HasAzGuard`, `AzGuardSubject`, `SubjectResolver`, `SubjectDirectory` | 05 §1, §5, §6, §9, D09–D11 | рецепты README зелёные | sonnet |
| P7.2 | Middleware `azguard.panel`, `azguard.can`, `azguard.context`; удаление старых middleware, атрибутов, Blade-директив | D32 | снимок alias'ов | sonnet |
| P7.3 | `config/azguard.php`, `AzGuardConfig`, `ConfigNormalizer`, проверки при boot | [07](07-configuration.md), D33 | V36 | sonnet |
| P7.4 | `Doctor`: проверки на панель и хранилище, проверки плагинов | [12 §2](12-operations-and-release.md#2-doctor-проверки) | `azguard:doctor --json` снимок | sonnet |
| P7.5 | `azguard:install` | D40 | V37 | sonnet |
| P7.6 | Тестовый kit, `AzGuardFake`, контрактные наборы (`Plugin…`, `GrantSource…`, `Restriction…`, `ChangePipe…`, `…Resolver…`) | D39, [06 §9](06-extension-points.md#9-контрактные-наборы-azguardtestingcontracts) | наборы прогоняются против встроенных реализаций | sonnet |
| P7.7 | Генераторы `azguard:make:panel|permissions|role|plugin|restriction|source|models` и стабы | 12 §1 | снимки генерации компилируются и проходят контрактные наборы | sonnet |
| P7.8 | Механический проход переименований [03](03-glossary-and-renames.md) + удаление: context-пакет, policy discovery, generated policies, abilities DTO, matcher'ы, `NullSafeUniqueIndex` (после upgrade), трейты записи | D03, D04 | arch: запрещённые имена (03 §2) отсутствуют в публичном API | sonnet |

## F8 — Filament

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P8.1 | Плагин без глобального конфига; `guardPanel()`, `manages()`; каталог ресурсов — плагином `azguard/filament` | [11 §2](11-filament.md#2-плагин) | V24 | sonnet |
| P8.2 | `FilamentGate`, ключи по slug, страницы и виджеты fail-closed | 11 §3–§4 | V25, уникальность ключей | sonnet |
| P8.3 | `RoleResource` | 11 §5.1 | V20–V23 | opus |
| P8.4 | `RoleAssignmentResource`, `DirectGrantResource`, выбор панели, пикеры; управление панелью с `administeredBy` | 11 §5.2, D23 | V26, V61 | sonnet |
| P8.5 | Свои поля в формах (`HasFilamentFields`, `FilamentFormExtension`), результат `pending` | 11 §5.3, §6 | V62–V63 | opus |
| P8.6 | `PanelsPage`, `DoctorPage` | 11 §5.4 | снимок страниц | sonnet |
| P8.7 | `azguard:filament:generate` (enum) | 11 §7 | снимок генерации | sonnet |

## F9 — Интеграции, документация, релизы

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P9.1 | `IntegrationContractTests`, пример интеграции `fixtures/example-integration` в CI | [10 §6–§7](10-integrations.md#6-проверка-интеграции), D51 | V58–V60 | opus |
| P9.2 | Документация и рецепты, включая руководства «Панели», «Плагины», «Пайплайны», «Хранилища и свои поля», «Модули», «Интеграция вашего пакета» | [12 §6](12-operations-and-release.md#6-документация), D42 | рецепты = сниппеты | sonnet |
| P9.3 | Гейты совместимости (manifest, снимки, пример интеграции) | 12 §5, D41 | гейты в CI | sonnet |
| P9.4 | Consumer-фикстуры (a)–(d) и матрица | 12 §4 | зелёная матрица | sonnet |
| P9.5 | Выпуск **0.4.0** + abandoned-пометки старых пакетов | D01, D03 | тег `v0.4.0`; upgrade-гайд | sonnet |
| P9.6 | Передать заметки V1–V6 по мосту в план Vaulter | [10 §10](10-integrations.md#10-заметки-для-vaulter-по-текущему-мосту) | задача в плане Vaulter создана | sonnet |
| P9.7 | **0.9.0** freeze candidate: dogfooding (одна и несколько панелей, модули, свои модели, Octane, queue, Redis, PG/MySQL), внешнее ревью API | D01 | чек-лист RC закрыт | opus (ревью) |
| P9.8 | **1.0.0**: Roave BC Check включён | D41 | все гейты блокирующие | sonnet |

## Зависимости

```
F0 ─► F1 (0.3.N)
F0 ─► F2 ─► F3 ─► F4 ─► F5 ─► F6 ─► F7 ─► F8 ─► F9
             │     │
             │     └── P4.4 (upgrade) нужен до P9.5
             └── P3.3 (плагины) нужен до всех встроенных плагинов F5–F6
P5.9 (Gate) требует P3.4 (Ownership O(1));  P5.10 требует P5.2, P5.3, P5.4
P5.11 требует P4.3;  P6.2 требует P3.4 (мета-права в каталоге) и P5.1
P8.* требует P6.1–P6.3;  P9.1 требует F5–F6
```
