# 01 — Независимая проверка: с чем согласен, что оспариваю, что найдено нового

Снимок кода: `50fe4af` (= `253cbf4` по прикладному коду `packages/`; между ними только документы аудита).
Метод: полное чтение исходников трёх пакетов (≈18,7 тыс. строк `src`), миграций, конфигов, стабов генератора,
выборочно тестов и документации; сверка с мостом `vaulter-azgard` в соседнем репозитории.

«Подтверждено статически» = прочитанная цепочка вызовов с путями и строками. «Воспроизведено» = исполняемый probe
из [evidence/](evidence/README.md) на SQLite. Где probe не запускался — сказано явно; сценарии динамической
проверки для остального — [14-verification.md](14-verification.md).

## 1. Итог в одном абзаце

Оба предыдущих прохода правильно видят «размытую границу API» и «модели как неявный API», но оба смотрят на
AzGuard как на **библиотеку, которую нужно заморозить**, а не как на **движок авторизации, семантику которого
нужно сначала сделать однозначной**. При повторном чтении я нашёл 24 новых дефекта; четыре из них (N01–N04) —
обход изоляции или эскалация прав, не замеченные ни Perplexity, ни Codex. Их общий корень один: у AzGuard
нет единой модели «кто — что — где»: панель выбирается четырьмя разными правилами, «назначение в контексте»
реализовано дважды (entity-scoped roles и context grants), superadmin — это случайное значение `*` в любом
источнике, а запись прав не имеет ни актора, ни политики делегирования. Поэтому целевая архитектура строится
вокруг **явного запроса** (`subject × permission × context`), **единой модели назначений** и **отдельного
административного API с актором** — а уже потом вокруг snapshot'ов и тегов.

## 2. Исходный аудит (Perplexity): позиция по пунктам

| Тезис | Позиция | Основание / что делаю вместо |
|---|---|---|
| API размечен не полностью (151/32/16/103) | **Согласен, решение другое** | Числа подтверждены Codex. Но разметка 150 классов тегами — не граница. Граница задаётся **расположением**: `Contracts\` (@api/@spi), `Internal\` и `Persistence\` (internal по namespace), плюс машинный `api-manifest.json` на пакет и arch-тест. Теги только `@api`/`@spi`/`@internal`; `@experimental` не вводится ([D12](02-decisions.md#d12)) |
| Расширения используют internals core | **Согласен, решение другое** | Context-пакет не «плохо изолированное расширение», а часть ядра, вынесенная в отдельный Composer-пакет: core уже содержит его контракты (`ContextGuard`, `ContextGrantBuilder`, `PermissionContext`) и `hasPermissionIn()`. Сливаю context в core ([D03](02-decisions.md#d03)); Filament переходит на Administration API ([D30](02-decisions.md#d30)) |
| Предложенный SPI (`PermissionCacheInvalidator`, `PermissionStateStore::advanceRevision`) | **Оспариваю** | Две независимые операции `current/advance` позволяют нарушить атомарность «запись + ревизия» (Codex прав). Ревизия — внутренность `AzGuardDatabase::mutate()`; наружу — только `StateToken` для чтения ([D24](02-decisions.md#d24)) |
| API snapshot неполон | Согласен | Плюс семантические снимки: конфиг, события, команды, схема, грамматика ([12 §5](12-operations-and-release.md#5-гейты-совместимости)) |
| Модели — неявный API; выбрать строгий вариант | **Согласен со строгим вариантом, с поправкой** | Модели остаются подменяемыми (`azguard.models.*`) и читаемыми, но **любая запись** — только через `AccessManager`; прямые записи unsupported, doctor ловит известные обходы. Отдельный storage-port в 1.0 не обещается ([D02](02-decisions.md#d02), [D22](02-decisions.md#d22)) |
| `guard:install` запускает общую `migrate` | Согласен | Плюс игнорируется код выхода (C10). Новый install — [D40](02-decisions.md#d40) |
| Идентичность роли нестабильна → `RoleId` | **Согласен, шире** | Проблема не в отсутствии id, а в том, что FQCN класса записан **в строки БД** (`roles.class_name`, `model_has_scopes.scope_class`) и при переименовании класса проверки держателей роли **бросают исключение** (N12). Идентичность — `RoleKey(realm, key)`, класс — сменяемая привязка ([D14](02-decisions.md#d14)) |
| Panel ID не валидируется | Согласен | Грамматика — та же, что у ключа профиля Vaulter ([D06](02-decisions.md#d06)) |
| Строки и enum ведут себя по-разному | **Согласен, решение проще** | Не два типа строк (`qualified`/`local`), а **только квалифицированные строки**; локальная форма существует лишь у enum/классов, привязанных к realm. Неквалифицированная строка — исключение ([D05](02-decisions.md#d05)) |
| Термины пересекаются | Согласен | Словарь другой: «Panel» переименовывается (коллизия с Filament panel — как в Vaulter), «Grant» — только прямая выдача права ([03](03-glossary-and-renames.md)) |
| Manager перегружен | Согласен | Разделение: `Authorizer` (чтение), `AccessManager` (запись), `RealmRegistry`, `PermissionCatalog`; фасад — тонкий ([D09](02-decisions.md#d09)) |
| Getter naming, mutable Panel, Builder с IO | Согласен | `Realm` — readonly после сборки; `GrantBuilder` удаляется (IO-операции — методы `AccessManager`) |
| Traits слишком велики | Согласен | Тонкий `HasAzGuard`: только чтение; записи и связи — не в host-модели ([D10](02-decisions.md#d10)) |
| Gate::before перехватывает unknown abilities | **Факт неверен** (Codex прав) | `Authorizer::check()` уже возвращает `null` для чужих abilities. Реальные проблемы — другие: Gate игнорирует настроенный resolver (N03) и `additive`-режим рождает boilerplate-политики (N19). Решение — authoritative для своих ключей ([D26](02-decisions.md#d26)) |
| Context должен стать plugin API | **Оспариваю** | Слияние в core, context — измерение каждого назначения ([D03](02-decisions.md#d03), [D15](02-decisions.md#d15)) |
| Cache key должен учитывать контекст | **Факт неверен** (Codex прав) | Дискриминатор в ключе есть; дефект — неинъективная кодировка (C01) |
| Filament напрямую работает с persistence | Согласен, **серьёзнее** | Filament позволяет вписать произвольный `class_name` роли — эскалация и DoS (N02) |
| События: нет id/времени/actor, Eloquent в payload | Согласен, шире | Плюс диспатч **до commit** и разные события у разных входов (N11). Единый конвейер как в Vaulter ([D28](02-decisions.md#d28)) |
| Префикс таблиц `az_guard_*` | **Согласен с целью, не с именами** | Префикс `azg_` (параллель `v_` у Vaulter), а главное — другая схема: единые назначения вместо `model_has_roles`/`model_has_scopes`/`az_direct_grants`/`az_guard_context_roles` ([08](08-data-model-and-migration.md)) |
| Configurable tables ≠ configurable schema | Согласен | Остаётся один `table_prefix`, колонки фиксированы ([D34](02-decisions.md#d34)) |
| Config: 5 settings-классов | **Частично** | Один `AzGuardConfig` на пакет, читающий через `Config\Repository` без кэширования (как в Vaulter D26) |
| Сохранить 3 пакета, слои внутри | **Оспариваю число** | 2 пакета: `azguard` (core+context) и `azguard-filament` ([D03](02-decisions.md#d03)) |
| Composer isolation: `composer install --working-dir` | Поправка Codex верна | Consumer-фикстуры на собранных архивах ([12 §4](12-operations-and-release.md#4-релиз-и-артефакты)) |
| Lockstep или независимо | Выбираю lockstep | Один тег на монорепо, `self.version` между пакетами (как Vaulter) |
| Mutation score завышен | Согласен (с формулировкой Codex) | Отчёт с denominator и исключениями |
| Dependency matrix | Поправка Codex верна | Матрица есть; добавить consumer-установку и Filament minors |
| 0.9.0 freeze candidate | Согласен | Путь: **0.4.0 (canon break) → 0.9.0 RC → 1.0.0** ([D01](02-decisions.md#d01)) |

## 3. Codex (additional-audit, C01–C12): позиция

| Находка | Позиция |
|---|---|
| C01 коллизия дискриминатора контекста | Согласен и **усиливаю**: доведено до межзапросной утечки через durable-кэш — грант контекста `(workspace, "a:7")` выдаётся в контексте `("workspace:a", 7)` ([evidence P07](evidence/README.md)). Решение — общий `IdentityCodec` для `SubjectRef`/`ContextRef`, одинаковый для БД, кэша и событий; `7` и `'7'` — **одна** идентичность, тип контекста не содержит `:` ([D07](02-decisions.md#d07)) |
| C02 временный контекст не восстанавливается | Согласен. Корень — мутирующий API контекста; одноразовая проверка передаёт контекст **аргументом** запроса, а не через `set()/restore` ([D16](02-decisions.md#d16)) |
| C03 ревизия с реплики | Согласен. По умолчанию все чтения авторизации — на write-PDO (`database.reads = primary`), и ревизия, и источники ([D24](02-decisions.md#d24)) |
| C04 разный mutation contract | Согласен, шире: `Role::delete()` вне Filament каскадно удаляет назначения **без** bump ревизии; `HasDirectGrants::grant()` не шлёт событий. Единственный путь записи — `AccessManager` ([D22](02-decisions.md#d22)) |
| C05 explain не объясняет один snapshot | Согласен + N24 |
| C06 одно ограничивающее расширение | Согласен. Упорядоченный реестр `Constraint` с ключами `vendor/name` ([D20](02-decisions.md#d20)) |
| C07 wildcard обходит контекст | Согласен, и хуже: class-role `*` обходит **и границу панели** (N01). Superadmin — отдельная политика ([D19](02-decisions.md#d19)) |
| C08 неоднозначный и mutable registry панелей | Согласен ([D06](02-decisions.md#d06)) |
| C09 PHPStan `@internal` не граница пакетов | Согласен. После слияния context остаётся одна граница core↔filament, её держит arch-тест + manifest |
| C10 installer игнорирует exit code | Согласен, подтверждено (`InstallCommand.php:27,46`) |
| C11 гибкость сильнее storage contract | Согласен + N15 (миграции и context на default connection, `ContextRole` final, user-модель зашита) |
| C12 качество ≠ один процент | Согласен |
| Предпочтение варианта B (kernel + adapters) | **Частично.** Беру B внутри **одного** дистрибутива: чистое ядро значений (`Kernel\`) без Illuminate, но Eloquent — единственное поддерживаемое хранилище 1.0; внешние данные подключаются как `PermissionSource` (read-only), а не как замена store ([D02](02-decisions.md#d02)) |

## 4. Новые находки (не было ни в аудите, ни у Codex)

Серьёзность: **S1** — обход изоляции/эскалация прав или ложное разрешение; **S2** — неверное поведение,
нарушение контракта, расхождение с документацией; **S3** — долг, мешающий 1.0.

### N01 (S1). Wildcard class-роли действует во всех панелях; документация обещает обратное

`ClassRoleGrantSource::permissionsFor()` загружает **все** роли пользователя без фильтра по панели и
оставляет `*` для любой запрошенной панели (`packages/core/src/Registry/Sources/ClassRoleGrantSource.php:74,126`).
Роль при этом «принадлежит» панели (`RoleIdentity::persistedName()` пишет `panel:name`, sync запрещает регистрировать
класс в двух панелях). Итог: class-роль панели `app` с `permissions() = ['*']` делает пользователя superadmin в
`admin` и в любой будущей панели. `docs/basic-usage/super-admin.md` («Scope of wildcard») утверждает прямо
противоположное: «`$user->hasPermission('admin.users.delete'); // false — different panel`».

Вторая половина дефекта: `hasPermission()` оценивает **любой** ключ по набору панели по умолчанию
(`HasPermissions.php:34` → `resolveDefault()` → `'app'`), не глядя на префикс ключа. Wildcard в панели `app`
разрешает `admin.users.delete`, даже если бы источник был panel-scoped. DB-роль с `*` при этом panel-scoped
(`role_permissions.panel_id`), т. е. одно и то же значение у двух видов ролей имеет разный радиус.
Воспроизведено: [evidence P01](evidence/README.md#p01).

### N02 (S1). Filament позволяет вписать роли произвольный `class_name`; политики делегирования нет

`RoleResource` показывает текстовое поле `class_name` (`packages/filament/src/Resources/RoleResource.php:90`),
а `CreateRole`/`EditRole` намеренно обходят защиту `$fillable` (C-11) прямым присваиванием
(`Pages/CreateRole.php:33`, `Pages/EditRole.php:42`). Кто может редактировать роли, может:

- привязать свою роль к `AzGuard\Roles\SuperAdminRole` (если super-admin ещё не синхронизирован — unique по
  `class_name` не мешает) или к любому классу `RoleInterface` с `*` → межпанельный superadmin (см. N01);
- вписать несуществующий класс → `Role::getRoleLogic()` → `RoleIdentity::logicOrFail()` бросает исключение
  (`Models/Role.php:81`) на **каждой** проверке прав каждого держателя роли, включая `Gate::before`, — отказ
  авторизации для группы пользователей.

Шире: ни в Filament, ни в core нет понятия «кто вправе выдавать»: любой пользователь с доступом к
`DirectGrantResource` выдаёт любое право любой панели любому пользователю, в том числе себе; `GrantBuilder`,
трейты и CLI не знают актора (C-11 защищал только mass assignment). Воспроизведено (DoS-часть): [evidence P02](evidence/README.md#p02).

### N03 (S1, условно). `Gate::before` игнорирует настроенный resolver

`Authorizer` получает **конкретный** `EffectivePermissionResolver` (`Guard/Authorizer.php:41`), тогда как трейты и
фасад — `PermissionResolverInterface`, который подменяется ключом `az-guard.resolver`. Конфиг обещает
«every check() call resolve through these bindings» (`config/az-guard.php:18-29`). Хост, чей resolver
**сужает** права (tenant, лицензия), получает расхождение: `$user->hasPermission()` — `false`, `Gate::allows()`/
`@can`/`can:` middleware — `true`. `ExtensionSwapTest` проверяет только `AzGuard::isSuperAdmin()`, не Gate.
Воспроизведено: [evidence P03](evidence/README.md#p03).

### N04 (S1). Изоляция запросов `HasScopedRoles` не изолирует

Глобальный scope из `bootHasScopedRoles()` (`Concerns/HasScopedRoles.php:53-137`) — единственный механизм,
который в конфиге назван «query-scope isolation» (`config/az-guard.php:170`):

1. **Fail-open без аутентификации**: `if (! Auth::check()) return;` (строка 62) — в очереди, консоли, на
   публичном маршруте запрос возвращает **все** строки. То же для пользователя без метода `scopes()` (строка 68).
2. **Fail-open без назначений**: пользователь без единой scoped-строки для этого типа модели получает запрос без
   фильтра — видит всё.
3. **AND-композиция**: для каждой строки назначения вызывается `apply()` на одном builder (строка 135); при
   фильтре вида `where(id, entity)` (как в тестовом `ScopedFilterRole`) пользователь с ролью на двух проектах видит
   **ноль** проектов. Контракт `ScopeInterface` не говорит, как объединять.

Воспроизведено: [evidence P04](evidence/README.md#p04).

### N05 (S2). Мост Vaulter → AzGuard отказывает в любом реальном хосте

`vaulter-azgard` вызывает `hasPermissionIn($type, $id, 'documents.view', panelId: null)` и `isSuperAdmin(null)`,
а его docblock утверждает: «null lets AzGuard resolve the panel from each permission key's first segment»
(`vaulter/packages/azgard/src/AzgardGuardAdapter.php:20-22`). Это верно только для `hasScopedPermission`;
`hasPermissionIn`/`isSuperAdmin` берут `default_panel ?? 'app'`, а строковый ключ `documents.view` проверяется как
есть. В хосте с зарегистрированной панелью `app` каталожный фильтр отбрасывает неквалифицированный ключ, и
workspace-лейн Vaulter **всегда** отказывает (кроме wildcard). Интеграционный тест Vaulter зелёный, потому что в
нём не зарегистрировано ни одной панели и фильтр каталога пропускается. Корень — асимметрия строка/enum
(`PermissionName::resolve`) и четыре правила выбора панели (N09). Воспроизведено: [evidence P05](evidence/README.md).

### N06 (S2). Контекстная проверка без context-пакета молча становится глобальной

`hasPermission($perm, $panel, $context)` при отсутствии `ContextGuard` возвращает **глобальный** результат
(`HasPermissions.php:40-43`), т. е. вызывающий, явно попросивший сужение до workspace, получает allow по
глобальной роли. Соседний `hasPermissionIn()` в той же ситуации возвращает `false` с warning. Одно намерение —
две противоположные политики отказа. Воспроизведено: [evidence P06b](evidence/README.md).

### N07 (S2). Merge strategy контекста — одна на все панели

`MergeStrategy` биндится из единственного ключа `az-guard-context.merge_strategy`
(`packages/context/src/AzGuardContextServiceProvider.php:50`), а `ContextPermissionLayer` применяется к каждой панели.
`DenyWithoutContextStrategy`/`ContextOnlyStrategy` обнуляют права в панели, где контекста нет по смыслу
(например `admin`). Хост с `admin` + `app` не может включить строгую изоляцию только для `app`.
Воспроизведено: [evidence P06](evidence/README.md).

### N08 (S2). `hasScopedPermission` живёт по своим правилам

`HasScopedRoles::hasScopedPermission()` (строки 352–411): DB-роли пропускаются (`if ($logic === null) continue;`,
строка 396), шаблоны `app.docs.*`/`**` не матчатся (только точный ключ или `*`), каталог не применяется,
результат не кэшируется и не ревизионируется. Та же роль через глобальное назначение даёт больше прав, чем через
scoped. Воспроизведено: [evidence P08](evidence/README.md).

### N09 (S2). Четыре правила выбора панели; префикс ключа игнорируется

| Путь | Правило |
|---|---|
| `hasPermission()/permissionSet()/hasGrant()` | явная → `default_panel` → `'app'` (`PanelResolver::resolveDefault`) |
| `Gate::before` (`Authorizer`) | текущая → `default_panel`, если зарегистрирована → единственная → `null` |
| `GrantBuilder`, `ContextGrantBuilder` | явная → текущая → исключение (`resolveOrFail`) |
| `DirectGrantPolicy`, `CheckDirectGrant`, `CheckAccess` | явная → текущая → `null`/сырой ключ |

При этом квалифицированный ключ `admin.users.ban` уже содержит панель, но ни один путь её не использует для
выбора набора. Следствия: `@can('admin.users.ban')` на запросе панели `app` — `null` → отказ;
пример `Route::middleware(['auth:admin', 'can:admin.users.ban'])` из `docs/basic-usage/multiple-guards.md` не работает
при двух панелях; там же утверждается, что `hasPermission()` «resolves against the current panel» — неверно.
Воспроизведено: [evidence P09](evidence/README.md).

### N10 (S2). «Назначение в контексте» реализовано дважды

| | core: entity-scoped roles | context: context grants |
|---|---|---|
| Таблица | `model_has_scopes` | `az_guard_context_roles` |
| Что назначается | роль | право |
| Идентичность контекста | морф модели (`scope_entity_type/id`, тип по `morph_type`) | строка (`context_type`, `context_id varchar(64)`) |
| Проверка | `hasScopedPermission($perm, Model)` | ambient `SetAuthorizationContext` + `MergeStrategy` или `hasPermissionIn()` |
| Wildcard | `*` разрешён | `*` запрещён |
| Кэш | нет | общий `PermissionCache` с дискриминатором |
| Панель | `panel_id NULL` = «любая» | обязательна |

Это корень N07, N08, N16 и ~1100 строк driver-специфичного DDL (`NullSafeUniqueIndex` 781 + `AssignmentDeduplicator`
319), нужных только потому, что в идентичности назначения есть NULL-колонки.

### N11 (S2). События неприменимы как интеграционный контракт

- диспатч **внутри** транзакции (`GrantBuilder.php:138-151`, `HasRoles::assignRole`): слушатель аудита видит
  незакоммиченное состояние, при откате внешней транзакции событие уже ушло;
- payload — Eloquent-модели (`GrantGiven::$grant`, `RoleAttached::$model/$role`), без id события, времени, актора;
- разные входы — разные события: `HasDirectGrants::grant()/revoke()` и Filament-revoke событий не шлют;
  `GrantGiven` приходит и на идемпотентный повтор без изменений; `revokeAll()` кодирует «всё» как `permissionKey = '*'`
  (неотличимо от отзыва superadmin);
- нет событий на создание/удаление ролей и изменение прав роли;
- `AzGuardFake::assertDenied()` проверяет **отзыв** гранта, а не отказ в доступе (`Testing/AzGuardFake.php:86`).

Воспроизведено (первые три пункта): [evidence P11](evidence/README.md).

### N12 (S2). Идентичность роли — FQCN, записанный в данные

Переименование или перенос класса роли: `guard:sync-roles` сопоставляет по `class_name` и либо создаёт новую строку
(по умолчанию `BaseRole::getName()` выводится из имени класса, значит меняется и `panel:name`), либо отказывает с
коллизией имени; старые назначения остаются на строке с несуществующим классом, и **каждая** проверка прав их
держателей бросает `InvalidRoleClassException` (`Models/Role.php:81`) — авария вместо миграции.
`model_has_scopes.scope_class` денормализует тот же FQCN ещё раз: после переименования фильтр изоляции молча
перестаёт применяться (лишь warning). Воспроизведено: [evidence P02](evidence/README.md#p02).

### N13 (S2). Superadmin — побочный эффект значения `*` в любом источнике

`*` принимают: class-роли (межпанельно, N01), DB-роли (`role_permissions`), прямые гранты через `GrantBuilder`,
трейт и `guard:grant` (документировано как «Option 3», `docs/basic-usage/super-admin.md`), любой пользовательский
`GrantSource`. `ContextGrantBuilder` тот же `*` запрещает. Нет отдельного права «выдавать superadmin», нет
журнала break-glass, нет способа сказать «superadmin не обходит tenant-ограничение». Шаблон `app.**` даёт все
права панели, но `isSuperAdmin()` для него `false` — два разных «всё». Воспроизведено: [evidence P14](evidence/README.md).

### N14 (S2). Записи без валидации и молчаливые no-op

`assignRole()/removeRole()/assignScopedRole()` пропускают неизвестную роль без ошибки (`HasRoles.php:76,108`,
`HasScopedRoles.php:157,226`); опечатка в сидере не видна. `GrantBuilder::grant()` и `guard:grant` не сверяют ключ с
каталогом: грант записывается, а при чтении отбрасывается фильтром каталога (debug-лог) — «выдано, но не действует».
Воспроизведено: [evidence P13](evidence/README.md).

### N15 (S2). Настраиваемое хранилище настраивается частично

Модели могут жить на отдельном соединении (Config проверяет, что все четыре на одном), но миграции создают
таблицы через `Schema::`/`DB::table()` на соединении по умолчанию, `ContextPermissionLayer` читает `DB::table()`
(`packages/context/src/ContextPermissionLayer.php:63`), `ContextRole` — `final` и не входит в `models.*`.
Пользовательская модель зашита как `auth.providers.users.model` и ключ `'id'` в `Role::users()`,
`SuperAdminCommand`, `ResolvesUserModel`, трёх Filament-ресурсах. Та же болезнь, что N01 в Vaulter.

### N16 (S2). Одна стратегия ключей на все морфы; три правила равенства идентичности

`column_names.morph_type` задаёт тип **всех** полиморфных id: пользователей, сущностей scoped-ролей, grantable.
Хост с int-пользователями и UUID-проектами не может назначить scoped-роль. Контекст хранит `context_id` строкой, а
`AuthorizationContext::equals()` сравнивает строго (`7 !== '7'`), тогда как БД и кэш считают их равными.
Та же болезнь, что N02 в Vaulter.

### N17 (S2). Горячий путь дороже, чем выглядит

- каждая проверка вне транзакции делает `SELECT` ревизии (`PermissionStateRevision::current()`,
  `PermissionCache.php:59-67`) — N проверок на странице = N запросов даже при тёплом кэше;
- каждый `forUser()` вызывает `Config::assertAuthorizationConnectionsAligned()` — инстанцирует четыре модели
  (`EffectivePermissionResolver.php:55`, и ещё раз в каждом источнике);
- `Gate::before` на **каждую чужую** ability (`viewAny`, `update` политик хоста) выполняет
  `CatalogKeyMatcher::owns()` с линейным обходом каталога панели (`Permissions/CatalogKeyMatcher.php:25`);
- `PanelProvider::boot()` на каждом запросе сканирует ФС в поисках `*Policy.php` (`Guard/PolicyDiscovery.php:26`) и
  регистрирует `Gate::policy()` по конвенции;
- кэш полностью обходится при **любой** транзакции на соединении авторизации: хост, оборачивающий запрос в
  транзакцию, теряет кэш; все тесты хоста с `RefreshDatabase` никогда не исполняют кэш-путь.

Воспроизведено (первый и последний пункты): [evidence P10, P10b](evidence/README.md).

### N18 (S2). Filament: глобальное состояние и fail-open

- `AzGuardPlugin::register()` перезаписывает глобальный `config('az-guard-filament.*')` на каждой Filament-панели —
  последняя побеждает для всех потребителей конфига (`AzGuardPlugin.php:164`);
- `Resource::checkPolicyExistence(false)` — статический вызов на класс ресурса, общий для всех панелей;
- `PageWidgetAccessEvaluator` при невозможности определить пользователя/метод возвращает `true` (fail-open,
  строки 43–55);
- ключ страницы/виджета строится из `class_basename()` класса, ресурса — из `class_basename()` модели
  (`FilamentDiscovery.php:64,73,90`): две `Settings` в разных кластерах или два `Post` в разных namespace делят одно право;
- `RoleUsersRelationManager` грузит всех пользователей в select (`pluck($labelColumn, 'id')`, строка 45);
- колонка «User» сравнивает `grantable_type` с FQCN модели — при morph map подпись ломается.

### N19 (S3). Additive Gate порождает двойной путь проверки

Из-за `null` на «своё право без гранта» генератор создаёт политики, каждый метод которых повторяет
`$this->allows(Permission::X)`; `PolicyAttributeRegistrar` регистрирует `Gate::define(ключ → метод политики)`, а
`PanelProvider` — `Gate::policy(Model → Policy)` по конвенции ФС. Методы названы `canUpdate`, поэтому
Laravel-ability `update` (`$user->can('update', $post)`) их не находит. Один вопрос «можно ли» проходит через три механизма.

### N20 (S3). Мёртвая и вводящая в заблуждение поверхность

`features.teams` и `teams.foreign_key` («Multi-team / tenant isolation» — реализации нет),
`middleware.register_middleware_in_appServiceProvider`, `fail_on_source_exception` (no-op), дублированный ключ
`scaffold` в `config/az-guard.php:79,126`, `Panel::path()` (не читается), `ResolvesContext::panelId()` (не вызывается),
`Role.level` («priority when merging permissions» при объединении множеств — без эффекта), фасад
`AzGuardContext::registerResolver` в docblock (не существует), `@azdirect`/`direct-grant` Gate-ability рядом с
обычной проверкой.

### N21 (S3). Установка и миграции

Код выхода вложенной `migrate` игнорируется (C10); core загружает миграции (`loadMigrationsFrom`), context — только
публикует; две миграции с одинаковой меткой `000006`; миграции читают `table_names`/`morph_type` в момент запуска —
смена ключей после установки молча расходится со схемой (как N28 Vaulter). Откат `000004` невозможен, пока есть
logic-less scoped-строки (`scope_class = NULL`) — наблюдалось при прогоне probe P08 (`DatabaseMigrations` не смог
откатить схему, пока строки не удалены).

### N22 (S3). Имена непоследовательны на каждой поверхности

Конфиг `az-guard`, alias'ы `azguard.*`, publish-теги `az-guard-config` и `azguard-context-config`, команды `guard:*`
и `make:guard-*`, таблицы `roles`/`model_has_roles`/`az_direct_grants`/`az_guard_*`, composer `homepage`
`axioma-studio/azguard` при репозитории `axiomasoft/azguard-private`; в Vaulter продукт пишется `azgard`.

### N23 (S3). Реестры и жизненный цикл

Singleton-менеджер хранит mutable `Panel`; `registerGrantSource()` после первого резолва resolver'а в текущем scope
не действует до следующего; `PermissionCatalog` регистрируется singleton'ом в `boot()` и замораживает набор builders
при первом обращении; `Config` — статические чтения глобального конфига во всех слоях.

### N24 (S3). `explain()` смешивает «не наше» и «нет гранта»

Для ability, которой нет в каталоге, `explain()` возвращает `NO_GRANT` (`Guard/Authorizer.php:96`), хотя `check()` в
этом случае воздерживается (`null`) и решение принимает Laravel. Плюс C05 (повторный опрос источников).

## 5. Что сознательно не делаю

- Не ввожу внешний policy engine (Cedar/OpenFGA): нет потребителя с relationship-графом; ReBAC-подобная иерархия
  контекстов — аддитивный T2 ([D21](02-decisions.md#d21)).
- Не обещаю в 1.0 заменяемое хранилище назначений: внешние данные — через `GrantSource` (read-only, с объявленной
  волатильностью). Порт записи — T2 при реальном потребителе.
- Не дроблю core на `kernel`/`contracts`-пакеты: граница внутри дистрибутива проверяется arch-тестом.
- Не переношу в итоговый дизайн утверждения Perplexity без сверки с кодом (R01–R30 написаны без доступа к репозиторию).
