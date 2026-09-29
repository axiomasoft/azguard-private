# 13 — Рабочие потоки: фазы и пункты для плана

Нумерация `Pn.m` стабильна: на неё ссылаются сценарии [14-verification.md](14-verification.md). План создаётся по
протоколу проекта (`plans/ACTIVE.md`), фазы и пункты переносятся **без перенумерации**.

## 0. Правила исполнения (в `Execution Rules` плана)

1. **Сначала T0-патч 0.3.x (F1), потом канон 0.4 (F2–F9).** T0 не ждёт канона: это безопасность текущих пользователей.
2. Каждый пункт начинается с **перевода соответствующего probe** из [evidence](evidence/README.md) в регрессионный тест
   с **обратным** ожиданием (красный → зелёный). Probe без инверсии = пункт не закрыт.
3. Исполнитель читает только указанные §-разделы досье; расхождение досье и кода → вопрос в `open-questions.md`, не
   импровизация.
4. Пункты с маршрутизацией **opus** не отдаются младшим моделям без готовой спецификации (`findings/Pn.m.md`).
5. После каждого пункта: `composer test`, Pint, PHPStan (уровень проекта), arch-тесты; падение или пропуск ≠ успех.
6. Переименования — одним механическим проходом по [03](03-glossary-and-renames.md) после F4 (когда новые типы
   существуют), с FQN-якорями; старый код удаляется в том же пункте, без временных алиасов (D01).
7. Изменения схемы — только через новые миграции; fresh и upgrade тестируются раздельно (D35).
8. Vaulter не изменяется из этого плана; пункты E2/E4/E5 ([10 §4](10-ecosystem-vaulter.md#4-порядок-релизов-и-действия-по-репозиториям))
   передаются в план Vaulter отдельной задачей.

## F0 — Подготовка

| Пункт | Что | Готово когда | Модель |
|---|---|---|---|
| P0.1 | ADR «Ecosystem conventions» (текст из [10 §1](10-ecosystem-vaulter.md#1-единые-конвенции-adr-ecosystem-conventions-одинаковый-текст-в-обоих-репозиториях)) в `docs/adr/`; ссылка для плана Vaulter | ADR в репо, задача для Vaulter создана | sonnet |
| P0.2 | Перенести probes в `tests/Audit/` как `todo`-набор (не в основной прогон) | `vendor/bin/pest tests/Audit` воспроизводит 19/19 | sonnet |
| P0.3 | Каркас consumer-фикстуры (чистый Laravel + path-архивы) в CI (job без блокировки) | job зелёный на 0.3 | sonnet |

## F1 — T0-патч 0.3.x (выпуск 0.3.N)

| Пункт | Что (минимальный патч в текущей архитектуре) | Решение / находка | Готово когда | Модель |
|---|---|---|---|---|
| P1.1 | `ClassRoleGrantSource`: `*` class-роли — только в панели роли (панель из `panel:name`; `super-admin` — глобально); docs super-admin исправить | D19 / N01, P01a | P01a инвертирован; P01b без изменений | opus |
| P1.2 | `hasPermission()`/`permissionSet()`: ключ с префиксом зарегистрированной панели оценивается по **этой** панели, если `$panelId` не передан | D05 / N01, N09, P01c, P09 | P01c, P09 (часть `hasPermission`) инвертированы | opus |
| P1.3 | `Authorizer` зависит от `PermissionResolverInterface`; `explain()` получает источники через отдельный internal-контракт | D26 / N03, P03 | P03 инвертирован | sonnet |
| P1.4 | `GrantBuilder`, `HasDirectGrants::grant`, `guard:grant` отвергают `*` (опция `--superadmin` для CLI) | D19 / N13, P14 | P14 инвертирован | sonnet |
| P1.5 | Filament: `class_name` только для чтения в формах; Create/Edit не пишут его; ресурсы AzGuard требуют мета-права `{panel}.azguard.roles.manage` / `…grants.manage`; «без эскалации» для выдачи прав | D23, D30 / N02 | V20–V23 (облегчённые) зелёные | opus |
| P1.6 | `Role::getRoleLogic()` на пути чтения → `null` + warning (исключение — только в sync/doctor) | D14 / N12, P02 | P02 инвертирован | sonnet |
| P1.7 | `Role`, `RolePermission` — `RevisionedPermissionModelWrites`; удаление роли вне Filament двигает ревизию | D22 / C04 | тест «удаление роли инвалидирует кэш» | sonnet |
| P1.8 | Контекст: дискриминатор `json_encode([$type, (string) $id])`; `AuthorizationContext` отвергает `:` в типе; `equals()` по канонической строке | D07 / C01, P07 | P07 инвертирован | sonnet |
| P1.9 | `ContextGuard::checkInContext()`: `set()` и `forgetRequestCache()` внутри `try` | D16 / C02 | probe Codex инвертирован | sonnet |
| P1.10 | `hasPermission(..., $context)` без context-пакета → `false` + warning (как `hasPermissionIn`) | D16 / N06, P06b | P06b инвертирован | sonnet |
| P1.11 | `merge_strategy` принимает карту `panel => strategy` (скаляр — глобальный fallback) | D15 / N07, P06 | P06 инвертирован при карте | sonnet |
| P1.12 | `bootHasScopedRoles`: без пользователя → `on_missing_user` (по умолчанию `empty`); пустые назначения → пусто; строки назначений объединяются через `orWhere`-группу; документировать границы | D31 / N04, P04 | P04a–c инвертированы | opus |
| P1.13 | `PermissionStateRevision::current()` и встроенные источники — `useWritePdo()` | D24 / C03 | тест SQL-маршрутизации (read/write соединения) | sonnet |
| P1.14 | `InstallCommand`: пропагировать код `migrate`; `default: false` | D40 / C10 | тесты installer | sonnet |
| P1.15 | Выпуск 0.3.N: CHANGELOG с классами изменений, раздел «Security» | D01 | тег, release notes | sonnet |

## F2 — Kernel: идентичность, грамматика, решение (0.4)

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P2.1 | `Kernel\Identity\*` (`PermissionKey`, `PermissionPattern`, `RealmId`, `RoleKey`, `SubjectRef`, `ContextRef`, `Actor`, `ActorRef`, `IdentityCodec`) | 05 §2, D07, D18 | property-тесты инъективности и round-trip (V01–V04) | opus |
| P2.2 | `Kernel\Grammar\{PermissionGrammar, PatternMatcher}` | D18 | таблица грамматики как data-provider; легаси-грамматики нет | sonnet |
| P2.3 | `Kernel\Decision\*` + `Kernel\Permissions\PermissionSet` | 05 §2, 09 §1 | unit-тесты значений | sonnet |
| P2.4 | Arch-правила [04 §2](04-packages-and-layout.md#2-слои-ядра-и-направление-зависимостей) | D02, D12 | arch-набор зелёный на пустых слоях | sonnet |

## F3 — Схема и хранение

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P3.1 | `AzGuardDatabase` (`connection`, `mutate` с ретраями и порядком блокировок, `read`), `StateRevision`, конфиг `database.*`, `ids.host_keys` | 08 §3, D24, D34 | тест: запись без `mutate` невозможна для официальных путей; ретрай на deadlock (PG) | opus |
| P3.2 | Fresh-миграция 0.4 на PG/MySQL/MariaDB/SQLite | 08 §1 | DDL-снимки на 4 СУБД | sonnet |
| P3.3 | Модели `Role`, `RolePermission`, `RoleAssignment`, `Grant`, `AuditEntry`; `GuardsDirectWrites` | 08 §1, D22 | запись модели вне `mutate` → исключение в testing | sonnet |
| P3.4 | `azguard:upgrade` + upgrade-миграция + фикстура 0.3 со всеми случаями таблицы 08 §5 | 08 §5 | V40–V42 зелёные на PG и MySQL | opus |

## F4 — Realm, каталог, роли, контексты

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P4.1 | `RealmBuilder`, `Realm`, `RealmProvider`, `RealmRegistry` (freeze, duplicate, replace) | 05 §6, D06 | V05–V07 | sonnet |
| P4.2 | Каталог: провайдеры `azguard/{enum,class,config,access}`, `Ownership` O(1), коллизии, `azguard:catalog:cache` | 05 §7, D36 | V08, бюджет D44 для чужой ability | opus |
| P4.3 | `RoleDefinition`, `CodeRole`, `RoleSynchronizer` (`formerKeys`, `--prune`) | 06 §7, D14 | V09–V11 | opus |
| P4.4 | `ContextPolicy`, `CurrentContext`, `ContextResolver` (route-резолвер), middleware `azguard.context`, `withinContext` | 09 §2, D15, D16 | V12–V14 | opus |

## F5 — Движок авторизации

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P5.1 | Источники `azguard/roles`, `azguard/grants`; реестр источников; `Volatility` | 06 §2, 08 §4 | контрактный набор источников зелёный для встроенных | opus |
| P5.2 | `Authorizer::decide()` по алгоритму 09 §1 | 09 §1–§3 | property-тесты P1–P7 (V15) | opus |
| P5.3 | Реестр constraints, `azguard/context-membership`, `ContextMembership` | 06 §3, 09 §4 | V16 | opus |
| P5.4 | `SuperadminPolicy`, платформенная роль | 09 §3, D19 | V17 | opus |
| P5.5 | `PermissionSetCache` (request + store), `StateToken`, `PolicyFingerprint`, `state_refresh` | 09 §6, D24, D25 | V18–V19, бюджеты D44 | opus |
| P5.6 | `decideMany()` | 09 §7, D27 | бюджет запросов D44 | opus |
| P5.7 | `explain()` + `azguard:explain` | 09 §9, D29 | V27 | sonnet |
| P5.8 | `GateBridge` (authoritative/additive, superadmin_scope, контекст из аргументов) | 09 §5, D26 | V28–V30 | opus |
| P5.9 | `Visibility` + trait `ContextAware` | 09 §8, D31 | V31–V33 | opus |

## F6 — Администрирование

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P6.1 | `AccessManager` + `OperationContext` + Actions + результаты; `MissingActorException` | 05 §5, D09, D22 | каждое действие: транзакция, ревизия, no-op без события | opus |
| P6.2 | `DelegationPolicy` по умолчанию + мета-права `AccessPermission` | D23 | V20–V22 | opus |
| P6.3 | `EventRecorder`, события 08 §6, `azg_audit_log`, after-commit | D28 | V34–V35 | sonnet |
| P6.4 | CLI: все пишущие команды через `AccessManager::asSystem()` | 12 §1 | снимок команд | sonnet |

## F7 — Laravel-поверхность и удаление старого

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P7.1 | Фасад, `SubjectAccess`, `HasAzGuard`, `AzGuardSubject`, `SubjectResolver`, `SubjectDirectory` | 05 §1, §4, §8, D10, D11 | рецепты README зелёные | sonnet |
| P7.2 | Middleware `azguard.can`; удаление старых middleware, атрибутов, Blade-директив | D32 | снимок alias'ов | sonnet |
| P7.3 | `config/azguard.php`, `AzGuardConfig`, `ConfigNormalizer`, boot-проверки | 07 | V36 | sonnet |
| P7.4 | `Doctor` + проверки 12 §2 | 12 §2 | `azguard:doctor --json` снимок | sonnet |
| P7.5 | `azguard:install` | D40 | V37 | sonnet |
| P7.6 | Тестовый kit, `AzGuardFake`, контрактные наборы | D39, 06 §10 | наборы прогоняются против встроенных реализаций | sonnet |
| P7.7 | Механический проход переименований [03](03-glossary-and-renames.md) + удаление: context-пакет, панели, policy discovery, generated policies, abilities DTO, matcher'ы, `NullSafeUniqueIndex` (после upgrade), трейты записи | D03, D04 | arch: запрещённые слова (03 §1) отсутствуют в публичных именах | sonnet |

## F8 — Filament

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P8.1 | Плагин без глобального конфига; `realm()`, `manages()` | 11 §1 | V24 | sonnet |
| P8.2 | `FilamentGate` authoritative, `FilamentCatalogProvider`, ключи по slug | 11 §2–§3 | V25, уникальность ключей | sonnet |
| P8.3 | `RoleResource` по 11 §4.1 | 11 §4.1 | V20–V23 | opus |
| P8.4 | `RoleAssignmentResource`, `GrantResource`, пикеры | 11 §4.2 | V26 | sonnet |
| P8.5 | `azguard:filament:generate` (enum) | 11 §5 | снимок генерации | sonnet |

## F9 — Документация и релизы

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P9.1 | Документация и рецепты | 12 §6, D42 | рецепты = сниппеты | sonnet |
| P9.2 | Гейты совместимости (manifest, снимки) | 12 §5, D41 | гейты в CI | sonnet |
| P9.3 | Consumer-фикстуры и матрица | 12 §4 | зелёная матрица | sonnet |
| P9.4 | Выпуск **0.4.0** + abandoned-пометки старых пакетов | D01, D03 | тег; upgrade-гайд | sonnet |
| P9.5 | Передача E2/E4/E5 в план Vaulter; совместный consumer-стенд (E6) | 10 §4 | V50–V52 зелёные на стенде | sonnet |
| P9.6 | **0.9.0** freeze candidate: dogfooding (single/multi-realm, Octane, queue, Redis, PG/MySQL), внешнее ревью API | D01 | чек-лист RC закрыт | opus (ревью) |
| P9.7 | **1.0.0**: Roave BC Check включён | D41 | все гейты блокирующие | sonnet |

## Зависимости

```
F0 ─► F1 (0.3.N)
F0 ─► F2 ─► F3 ─► F4 ─► F5 ─► F6 ─► F7 ─► F8 ─► F9
                   │            ▲
                   └── P3.4 (upgrade) нужен до P9.4
P5.8 (Gate) требует P4.2 (Ownership O(1));  P5.9 требует P5.1 и P4.4
P6.2 требует P4.2 (мета-права в каталоге) и P5.2
P8.* требует P6.1–P6.2
```
