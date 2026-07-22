# P0 ось C — корректность / безопасность (2026-07-18)

> Слой 1. Аудитор: fable (субагент P0.4). Read-only аудит по чеклисту phases/P0.md §P0.4.

## Чеклист

| C# | Проверка | Вердикт | Якоря | Заметка |
|:--|:--|:--|:--|:--|
| C-C1 | Panel-изоляция: strict-контракт, сценарии тихой потери изоляции | partial | packages/core/src/Concerns/HasScopedRoles.php:50, packages/core/src/Concerns/HasScopedRoles.php:81 | T1 closed (TAILS D5); открытые follow-ups: C-01, C-02, C-03 |
| C-C2 | Epoch/cache: unbounded рост, кросс-процессные гонки, Cache::lock | partial | packages/core/src/Registry/Resolver/PermissionCache.php:104, packages/core/src/Registry/Resolver/PermissionCache.php:117 | T6 closed; открытые follow-ups: C-04, C-05 |
| C-C3 | Wildcard-грамматика: семантика matcher, edge-cases, пре-1.0 решение | partial | packages/core/src/Registry/Matching/WildcardPermissionMatcher.php:30, packages/core/src/Registry/Values/PermissionSet.php:129 | `**` реализован opt-in (Hierarchical); дефолт остался legacy — C-06, C-07 |
| C-C4 | Отложенные breaking: пере-оценка при пре-1.0 свободе | partial | packages/core/src/Registry/Matching/HierarchicalPermissionMatcher.php:14 | F4 сделан (AbilitiesDto.php:45) · F22 открыт — делать сейчас (C-06) · F40 сделан (PermissionCatalog.php:61) · F51 сделан (префикс guard: на 22 командах) |
| C-C5 | Авторизационные пути: Gate::before порядок, isSuperAdmin, DirectGrant | partial | packages/filament/src/Permissions/ResourceGate.php:53, packages/core/src/Models/DirectGrant.php:52 | core-before union-only (true/null) — ок; Filament-before может вернуть false — C-08; C-09 |
| C-C6 | Mass-assignment / инъекции: fillable, morph-типы, LIKE | partial | packages/core/src/Models/Role.php:24, packages/core/src/Registry/Sources/DirectGrantSource.php:38 | INTEGRATION_FEEDBACK п.8 закрыт только доками — C-10, C-11, C-12 |
| C-C7 | Context-merge: эскалация контекстом, Deny/ContextOnly, ContextNotSetException | partial | packages/context/src/ContextGrantBuilder.php:84, packages/core/src/Registry/Resolver/EffectivePermissionResolver.php:94 | стратегии корректны; superadmin transcends narrowing — задокументировано в резолвере; эскалация `*` — C-13 |
| C-C8 | Octane/long-running: утечки состояния, полнота OctaneScopingTest | partial | packages/core/src/AzGuardServiceProvider.php:200, tests/Unit/OctaneScopingTest.php:18 | все per-request сервисы scoped; docblock AuthorizationContextManager.php:8 устарел («singleton») — C-14 |
| C-C9 | Events: payload (PII/утечки), контрактность | partial | packages/core/src/Events/AccessDecision.php:38 | payload минимален (userId, ключи), PII нет; SerializesModels — штатная семантика; мёртвое поле — C-15 |
| C-C10 | Миграции/схема: индексы hot-path, FK/каскады, уникальность | partial | packages/core/database/migrations/2026_01_01_000000_create_az_guard_tables.php:10 | role_permissions/direct_grants — unique+lookup-индексы ок; базовая миграция и scope-таблицы — C-16 |

## Находки

### C-01 — Global query-scope тихо отключается в console/queue-воркерах

- **Severity:** Blocker
- **Чек:** C-C1
- **Где:** packages/core/src/Concerns/HasScopedRoles.php:50
- **Суть:** `bootHasScopedRoles` выходит при `app()->runningInConsole()` — это true и для `queue:work`/`schedule:run`. Job, работающий от имени аутентифицированного пользователя (`Auth::login()` в job), читает модели БЕЗ scope-фильтра: тихая потеря изоляции данных вне HTTP. В docs поведение не описано (grep по docs/ пуст).
- **Рекомендация:** Явный контракт: либо задокументированный opt-in/opt-out (config-флаг вместо безусловного console-bypass), либо сузить условие до реального artisan-CLI (не воркеры). Решение семантики — P1/P2.

### C-02 — Нет явного strict-режима изоляции query-scope (приор C.10)

- **Severity:** Major
- **Чек:** C-C1
- **Где:** packages/core/src/Concerns/HasScopedRoles.php:72
- **Суть:** Без установленной панели применяются ВСЕ scope-строки (D5, аддитивно), контракт «нет панели» живёт только в комментарии; `az-guard.strict_panels` (Support/Config.php:222) касается лишь регистрации панелей, не изоляции запросов. Потребителю негде включить «падай, если панель не установлена».
- **Рекомендация:** Явный strict-контракт API (режим/метод) для query-scope: отсутствие панели = исключение или пустая выборка по выбору потребителя. Направление — P1/P2.

### C-03 — Stale scope_class тихо снимает фильтр выборки

- **Severity:** Major
- **Чек:** C-C1
- **Где:** packages/core/src/Concerns/HasScopedRoles.php:81
- **Суть:** `class_exists($scope->scope_class)` false (класс переименован/удалён без миграции строк) → фильтр молча не применяется, пользователь с ограниченной ролью видит все строки. Поведение задекларировано в докблоке миграции 000004 как «logic-less», но отличить осознанный null от протухшего имени класса невозможно, сигнала (лог/doctor) нет.
- **Рекомендация:** Log::warning на несуществующий scope_class + проверка в `guard:doctor`; null и «класс пропал» — разные состояния.

### C-04 — Epoch растёт без границы; при TTL=null орфанные записи копятся вечно

- **Severity:** Major
- **Чек:** C-C2
- **Где:** packages/core/src/Registry/Resolver/PermissionCache.php:107, packages/core/config/az-guard.php:155
- **Суть:** Открытый follow-up закрытого T6: `forgetForUser` только инкрементирует epoch, reset/границы нет; каждая v{N}-запись становится орфаном до своего TTL, а config допускает `expiration_time => null` («no expiry») — на персистентном сторе орфаны не умирают никогда (unbounded рост Redis).
- **Рекомендация:** Верхняя граница/реюз epoch или запрет TTL=null при персистентном сторе (валидация конфига); тема волны P1 (приор C.11).

### C-05 — Кросс-процессной проверки epoch-гонки нет; без LockProvider гонка возвращается

- **Severity:** Major
- **Чек:** C-C2
- **Где:** packages/core/src/Registry/Resolver/PermissionCache.php:117, tests/Feature/PermissionCacheEpochInvalidationTest.php:1
- **Суть:** Признаю явно: существующие epoch-тесты однопроцессные — реального кросс-процессного Redis race-теста НЕТ (известный гэп, вход P4). Плюс graceful degradation: на сторе без `LockProvider` bump выполняется без лока (ветка else) — документированная в T6 гонка отката epoch на таких драйверах остаётся возможной и нигде не сигналится.
- **Рекомендация:** Race-тест — P4; на не-LockProvider сторе — хотя бы одноразовый warning (RequestState::once), чтобы деградация не была немой.

### C-06 — Дефолтная wildcard-грамматика по-прежнему пересекает сегменты; флип отложен на 0.4.0

- **Severity:** Major
- **Чек:** C-C3
- **Где:** packages/core/src/Registry/Matching/WildcardPermissionMatcher.php:30
- **Суть:** F22-остаток: дефолтный matcher разворачивает `*`→`.*` («app.\*» покрывает «app.a.b»); корректный `HierarchicalPermissionMatcher` (`*`=сегмент, `**`=рекурсивно) существует, но только opt-in, флип дефолта запланирован на 0.4.0 deprecate-циклом. Пре-1.0 свобода (бриф п.3) позволяет флипнуть сейчас и не тащить legacy-грамматику в 1.0. Смягчение: `features.wildcard_permission` по умолчанию false.
- **Рекомендация:** Решить в P2.3: дефолт = Hierarchical, legacy — opt-out на один цикл. Вердикт C-C4: делать сейчас.

### C-07 — PermissionSet вне контейнера всегда берёт legacy-matcher

- **Severity:** Minor
- **Чек:** C-C3
- **Где:** packages/core/src/Registry/Values/PermissionSet.php:129
- **Суть:** `matcher()` при неподнятом контейнере молча падает на `new WildcardPermissionMatcher` — standalone-использование VO (unit-тесты потребителя, CLI-скрипты) матчит по legacy-грамматике даже когда приложение сконфигурировано на Hierarchical: расхождение результатов одного и того же набора.
- **Рекомендация:** Инжектировать/передавать matcher явно либо задокументировать расхождение на @api-границе PermissionSet.

### C-08 — Filament ResourceGate возвращает false из Gate::before — жёсткий deny

- **Severity:** Major
- **Чек:** C-C5
- **Где:** packages/filament/src/Permissions/ResourceGate.php:53
- **Суть:** `return (bool) $user->hasPermission(...)` — при отсутствии права before-хук возвращает false, что в Laravel останавливает всю цепочку: пользовательские policies, Gate-определения и before-хуки приложения (они регистрируются ПОСЛЕ пакетных) уже не могут разрешить действие. Ломает интеграционный контракт «union-only» (§6 п.6): core-Authorizer честно возвращает true/null, Filament-гейт — нет.
- **Рекомендация:** Возвращать `true|null` (deny — через отсутствие grant, а не через false) либо задокументировать hard-deny как осознанный enforcement-режим с opt-out.

### C-09 — Флаш кэша при update DirectGrant использует только новый panel_id

- **Severity:** Minor
- **Чек:** C-C5
- **Где:** packages/core/src/Models/DirectGrant.php:52
- **Суть:** `booted()`-флаш берёт `$grant->panel_id` (новое значение); при смене панели гранта A→B кэш панели A не инвалидируется — отозванный на A грант продолжает отдаваться из персистентного стора до TTL.
- **Рекомендация:** В updated-хуке флашить и `getOriginal('panel_id')` (и, симметрично, original grantable_id).

### C-10 — Морф-типы: `$user::class` против `getMorphClass()` в write/read путях грантов

- **Severity:** Major
- **Чек:** C-C6
- **Где:** packages/core/src/Registry/Sources/DirectGrantSource.php:38, packages/context/src/ContextPermissionLayer.php:62
- **Суть:** Открытый follow-up INTEGRATION_FEEDBACK п.8 (закрыт только доками): GrantBuilder.php:94, DirectGrantSource, ContextGrantBuilder.php:84 и ContextPermissionLayer пишут/читают `$user::class`, тогда как HasScopedRoles/ModelHasScope используют `getMorphClass()`. В приложении с morph map строки, созданные Eloquent-путём (Filament DirectGrantResource, morph-релейшны), хранят алиас — резолвер ищет FQCN: тихая потеря грантов (deny без сигнала).
- **Рекомендация:** Единый шов резолюции morph-типа (getMorphClass везде) + тест с enforceMorphMap.

### C-11 — Mass-assignment: class_name у Role и scope_class у ModelHasScope в fillable

- **Severity:** Major
- **Чек:** C-C6
- **Где:** packages/core/src/Models/Role.php:24, packages/core/src/Models/ModelHasScope.php:30
- **Суть:** `class_name` — носитель привилегий (getRoleLogic инстанцирует и раздаёт permissions, включая `*`), `scope_class` инстанцируется контейнером в query-scope. Оба в `$fillable`: типовой потребительский `Role::create($request->all())`/`update()` — вектор эскалации (подставить SuperAdminRole-класс) и инстанцирования произвольного класса, реализующего контракт.
- **Рекомендация:** Вывести привилегиеносные колонки из fillable (явные сеттеры/guarded) или задокументировать как security-контракт модели.

### C-12 — LIKE-поиск в DirectGrantResource не экранирует `%`/`_`

- **Severity:** Nit
- **Чек:** C-C6
- **Где:** packages/filament/src/Resources/DirectGrantResource.php:64
- **Суть:** `"%{$search}%"` — биндинг исключает SQL-инъекцию, но пользовательские `%`/`_` работают как метасимволы (админ-UI, риск низкий: лишние совпадения/медленный скан).
- **Рекомендация:** Экранировать LIKE-метасимволы в поисковой строке.

### C-13 — Context-грант `*` даёт panel-wide wildcard и минует catalog-фильтр

- **Severity:** Major
- **Чек:** C-C7
- **Где:** packages/context/src/ContextGrantBuilder.php:84, packages/core/src/Registry/Resolver/EffectivePermissionResolver.php:94
- **Суть:** Конкретная последовательность: `(new ContextGrantBuilder($user))->on('app')->inContext('workspace', 42)->grant('*')` — PermissionName::resolve('*') возвращает `*` как есть, строка пишется без валидации (RolePermissionValidator покрывает только RolePermission); при активном контексте merge даёт wildcard-сет, резолвер возвращается ДО catalog-фильтра — пользователь становится super-admin всей панели (включая ресурсы вне workspace), пока контекст активен. Ожидание потребителя «грант ограничен workspace» нарушено.
- **Рекомендация:** Запретить/валидировать `*` (и wildcard-паттерны) в context-грантах либо не короткоциркуитить catalog-фильтр для wildcard, пришедшего из layer.

### C-14 — Сброс currentPanel покрыт только Octane-событием; RequestState вне Octane-теста

- **Severity:** Minor
- **Чек:** C-C8
- **Где:** packages/core/src/AzGuardServiceProvider.php:200, tests/Unit/OctaneScopingTest.php:18
- **Суть:** AzGuardManager — singleton, currentPanel сбрасывается лишь листенером `Octane\Events\RequestReceived`; для queue-воркеров (long-running без Octane) сброс кодом пакета не подтверждён — job, вызвавший setCurrentPanel, может протечь панелью в следующий job. OctaneScopingTest проверяет 3 scoped-сервиса и manager, но не RequestState (тоже scoped — SP:119).
- **Рекомендация:** Листенер на JobProcessing (или документированный контракт «панель в jobs ставится явно»); дополнить Octane-тест RequestState — вход P4.

### C-15 — AccessDecision::winningSource никогда не заполняется

- **Severity:** Minor
- **Чек:** C-C9
- **Где:** packages/core/src/Events/AccessDecision.php:38, packages/core/src/Commands/ExplainCommand.php:106
- **Суть:** Поле контракта события объявлено и читается (`guard:explain` выводит «Winning source»), но Authorizer::explain() его не устанавливает ни в одной ветке — слушатели и CLI всегда получают null/«—»: мёртвое обещание контракта аудита.
- **Рекомендация:** Либо заполнять источник в explain(), либо убрать поле из контракта до 1.0 (пре-1.0 свобода).

### C-16 — Базовая миграция без down(); scope/role-pivot таблицы без PK/unique

- **Severity:** Minor
- **Чек:** C-C10
- **Где:** packages/core/database/migrations/2026_01_01_000000_create_az_guard_tables.php:10
- **Суть:** Миграция 000000 не имеет `down()` — rollback молча пропускается, база необратима (нарушение собственного канона migrations-правил репо). `model_has_roles` — без PK и unique(role_id, model_type, model_id); `model_has_scopes` — без unique по (model, entity, role_id, panel_id): гонка `firstOrCreate` в assignScopedRole даёт дубли строк. Hot-path индексы role_permissions/direct_grants — корректны (unique + lookup, миграция 000002:30-49).
- **Рекомендация:** Новой миграцией (не правкой применённых): down() для базовой, PK/unique-ограничения на обе таблицы.
