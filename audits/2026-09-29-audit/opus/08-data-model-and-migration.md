# 08 — Хранилища, модель данных, инварианты, миграция

Решения: [D07](02-decisions.md#d07), [D08](02-decisions.md#d08), [D13](02-decisions.md#d13), [D14](02-decisions.md#d14),
[D19](02-decisions.md#d19), [D22](02-decisions.md#d22), [D24](02-decisions.md#d24), [D28](02-decisions.md#d28),
[D34](02-decisions.md#d34), [D35](02-decisions.md#d35), [D46](02-decisions.md#d46).

## 1. Хранилища — простыми словами

- **Хранилище** — это «где лежат роли и выдачи панели»: соединение с БД + префикс таблиц + тип колонок для id хоста
  + классы моделей.
- По умолчанию все панели живут в **общем хранилище** `default` (таблицы `azg_*`), строки различаются колонкой
  `panel`. Это просто и достаточно для большинства проектов.
- Панели можно **развести**: указать именованное хранилище из конфига (`->storage('backoffice')`) или собственное
  (`Storage::own(prefix: 'admin_', connection: 'backoffice')`). Схема таблиц та же; миграцию генерирует команда, и в неё
  можно дописать свои колонки.
- У каждой **панели** — своя строка «версии состояния»: изменения в админке не сбрасывают кэш сайта, даже если
  они в одном хранилище.

```
хранилище default (connection: mysql, prefix: azg_)        хранилище backoffice (connection: backoffice, prefix: azg_)
┌───────────────────────────────────────────────┐          ┌─────────────────────────────────────────────┐
│ azg_roles            panel=site | panel=blog   │          │ azg_roles            panel=admin             │
│ azg_role_assignments panel=site | panel=blog   │          │ azg_role_assignments panel=admin (+department_id) │
│ azg_direct_grants    …                         │          │ azg_direct_grants    …                       │
│ azg_panel_state      site: rev 41, blog: rev 7 │          │ azg_panel_state      admin: rev 118          │
└───────────────────────────────────────────────┘          └─────────────────────────────────────────────┘
```

## 2. Схема хранилища (одинакова для любого хранилища; `{p}` — префикс)

Обозначения: `HK` — колонка ключа хоста по `host_keys` хранилища (`string` → `varchar(64)`, `bigint`, `uuid`, `ulid`);
`ID` — ASCII-идентификатор (MySQL: `charset ascii, collation ascii_bin`; PG/SQLite — обычный `varchar`; грамматика D07/D18
гарантирует ASCII, индексы укладываются в лимит InnoDB).

```
{p}roles
  id               bigint PK
  panel            varchar(64)  ID  NOT NULL
  key              varchar(64)  ID  NOT NULL
  label            varchar(191)     NULL
  description      text             NULL
  origin           varchar(16)      NOT NULL          -- code | database
  definition       varchar(255) ID  NULL              -- FQCN RoleDefinition (только code)
  is_superadmin    boolean          NOT NULL DEFAULT false
  rank             integer          NOT NULL DEFAULT 0
  meta             json             NULL              -- свои поля без миграции
  created_at, updated_at
  UNIQUE (panel, key)                 {p}roles_identity_uq
  UNIQUE (panel, definition)          {p}roles_definition_uq

{p}role_permissions                                   -- только для origin = database
  id, role_id FK → {p}roles ON DELETE CASCADE, permission varchar(255) ID NOT NULL, created_at
  UNIQUE (role_id, permission)

{p}role_assignments
  id               bigint PK
  role_id          bigint FK → {p}roles ON DELETE CASCADE
  panel            varchar(64)  ID  NOT NULL          -- из роли (роль не меняет панель)
  subject_type     varchar(128) ID  NOT NULL
  subject_id       HK               NOT NULL
  context_key      varchar(200) ID  NOT NULL          -- 'global' | '{type}:{id}'
  context_type     varchar(128) ID  NULL
  context_id       HK               NULL
  expires_at       timestamp        NULL
  granted_by_type  varchar(128) ID  NULL
  granted_by_id    varchar(64)  ID  NULL
  reason           varchar(255)     NULL
  meta             json             NULL
  created_at, updated_at
  UNIQUE (role_id, subject_type, subject_id, context_key)           {p}role_assignments_identity_uq
  INDEX  (panel, subject_type, subject_id, context_key)             {p}role_assignments_lookup_idx
  INDEX  (panel, context_type, context_id)                          {p}role_assignments_context_idx
  INDEX  (expires_at)

{p}direct_grants
  id, panel, permission varchar(255) ID NOT NULL, subject_type, subject_id, context_key, context_type, context_id,
  expires_at, granted_by_type, granted_by_id, reason, meta, created_at, updated_at   -- как выше
  UNIQUE (panel, subject_type, subject_id, permission, context_key)  {p}direct_grants_identity_uq
  INDEX  (panel, subject_type, subject_id, context_key)
  INDEX  (panel, context_type, context_id)
  INDEX  (expires_at)

{p}panel_state
  panel            varchar(64) ID PK
  revision         bigint NOT NULL
  updated_at       timestamp
{p}storage_state                                       -- одна строка на хранилище
  id               smallint PK (= 1)
  schema           json NOT NULL                     -- {version, table_prefix, host_keys, connection, upgraded_from}
```

Плагины со своими таблицами (например `azguard/audit` → `{p}audit_log`, подтверждения → `{p}approvals`) создают их
в том же хранилище через свою миграцию; ядро даёт им `Storage` (соединение, префикс) на шаге «Журнал».

Размер уникального ключа `{p}direct_grants` на MySQL (ascii): `panel` 66 + `subject_type` 130 + `subject_id` 66 +
`permission` 257 + `context_key` 202 ≈ 721 байт ≪ 3072 — никаких префиксных индексов и driver-специфичного DDL.
Требования к СУБД снижаются до PostgreSQL 13+, MySQL 8.0+/MariaDB 10.6+, SQLite 3.35+ ([Q15](15-owner-questions.md)).

## 3. Свои модели и колонки

| Способ | Когда | Как |
|---|---|---|
| `meta` (JSON) | немного полей, без индексов | каст в модели панели: `protected $casts = ['meta' => AsArrayObject::class]` или типизированный каст |
| Колонки в общем хранилище | поле нужно в индексе/фильтре, панель в общем хранилище | миграция хоста добавляет **nullable** колонку в `azg_role_assignments`; её использует только модель этой панели |
| Колонки в собственном хранилище | у панели своё хранилище | `azguard:storage:migration admin` генерирует миграцию в `database/migrations`; хост дописывает колонки |

Проверки при сборке: модель панели наследует базовую; у собственного хранилища `$table/$connection` модели совпадают
с хранилищем; колонки из `azguardRules()` и `decisionAttributes()` существуют (doctor сверяет с реальной схемой).

## 4. Инварианты (держит пайплайн изменений, проверяет doctor)

| # | Инвариант | Где держится |
|---|---|---|
| I1 | `assignment.panel = role.panel`; панель роли не меняется | `AssignRoleOperation`, `UpdateRoleOperation` |
| I2 | суперадмин-роли назначаются только с `context_key = 'global'` | `AssignRoleOperation` |
| I3 | `direct_grant.permission` — ключ или шаблон своей панели; точный ключ есть в каталоге; шаблон покрывает ≥ 1 ключ | `GrantPermissionOperation` |
| I4 | `role_permissions` — только у `origin = database`, шаблоны панели роли | `SetRolePermissionsOperation`; doctor для code-ролей |
| I5 | `context_key = codec(context_type, context_id)`; оба NULL ⇔ `global`; тип контекста принят панелью | операции + doctor |
| I6 | `expires_at` в будущем на момент записи | операции |
| I7 | любое изменение строк панели → +1 `panel_state.revision` этой панели в той же транзакции | `Storage::mutate()` |
| I8 | `definition` разрешается в `RoleDefinition`, подключённую к панели (иначе роль пустая + doctor error) | `RoleGrantSource`, doctor |
| I9 | никакого голого `*` в `permission` | грамматика |
| I10 | свои поля проходят `azguardRules()` и шаги `ValidatesChange` | шаг «Проверка» |

## 5. Порядок блокировок и ретраи

`Storage::mutate(string $panel, Closure $work, int $attempts = 3)`:

1. `BEGIN` на соединении хранилища;
2. блокировки по порядку: строки `{p}roles` по возрастанию `id` (`FOR UPDATE`) → строки выдач (через уникальные
   ключи; `INSERT … ON CONFLICT` вместо «прочитать-потом-вставить») → **последней** — строка `{p}panel_state` этой панели;
3. шаг «Журнал» (плагины) — внутри транзакции перед commit;
4. `COMMIT`; затем шаг «Уведомления»;
5. повтор на `40001`/`40P01` (PG) и `1213` (MySQL) до `$attempts` раз; уведомления для откаченных попыток не отправляются.

Строка версии панели — точка сериализации записей **одной панели**; панели друг друга не блокируют.

## 6. Upgrade 0.3.x → 0.4.0

Миграция `…_upgrade_azguard_03_to_04` (no-op на свежей установке) + команда
`azguard:upgrade --dry-run|--execute [--panel-map=old:new] [--panel-for-orphans=app] | --drop-legacy`. Данные 0.3
переносятся в хранилище `default` (панели с собственным хранилищем можно перенести позже командой
`azguard:storage:move {panel} {storage}`). Старые таблицы переименовываются в `*_legacy_03`.

**Предусловия (preflight, только чтение; отказ — до DDL):** провайдеры панелей переписаны на `panel(PanelBuilder)`;
id панелей проходят грамматику; role-классы реализуют `RoleDefinition`; типы контекстов без `:`; ключи прав проходят
грамматику 0.4.

| Источник 0.3 | Результат 0.4 | Отчёт/поведение |
|---|---|---|
| `roles` `super-admin` (класс `SuperAdminRole`) | роль `{panel}:superadmin` в **каждой** панели, назначения дублируются | по умолчанию; `--superadmin=global-plugin` — вместо этого подключить `GlobalSuperadminPlugin` с перечнем субъектов |
| `roles` `panel:name` с `class_name` | code-роль `(panel, definition::key())` | класс не найден → `database`-роль без прав + **error** |
| code-роль с `*` в `permissions()` | `is_superadmin = true` **в своей панели** | **изменение поведения** (N01): в 0.3 действовала во всех панелях — перечисляется |
| DB-роль, права в одной панели | `database`-роль `(panel, slug(name))` | |
| DB-роль с правами в нескольких панелях | по роли на панель, назначения дублируются | перечисляется |
| DB-роль без прав | панель из `--panel-for-orphans` | без флага — preflight-ошибка |
| `role_permissions` `*` | `is_superadmin = true` у роли этой панели | |
| `role_permissions` ключ вне каталога | переносится | warning; doctor покажет «мёртвые» права |
| `model_has_roles` | `azg_role_assignments` (`global`) | |
| `model_has_scopes` с `role_id` | `azg_role_assignments` с контекстом `(scope_entity_type, scope_entity_id)` | `panel_id` ≠ панели роли → пропуск + отчёт; `NULL` («любая») → панель роли |
| `model_has_scopes` без `role_id` | не переносится | перечисляется |
| `az_direct_grants` | `azg_direct_grants` (`global`) | `*` → назначение `{panel}:superadmin` с тем же сроком |
| `az_guard_context_roles` | `azg_direct_grants` с контекстом | |
| `az_guard_permission_state.revision` | `azg_panel_state.revision + 1` для каждой панели | кэш старого формата недействителен |

Выполнение: создание таблиц → копирование одной транзакцией (PG — вместе с DDL; MySQL — DDL отдельно, копирование
транзакционно, идемпотентно по `storage_state.schema.upgraded_from`) → переименование старых таблиц. `down()` —
восстановление из `*_legacy_03`, пока они не удалены. Проверка — V40–V42 ([14](14-verification.md)).

## 7. Каталог событий

База `AzGuard\Events\AccessEvent` (readonly): `eventId` (ULID), `occurredAt`, `panel`, `actor: ActorRef`,
`correlationId: ?string`, `stateRevision: int`, `type(): EventType`. Payload — только значения (без Eloquent):

| Событие | `EventType` | Поля |
|---|---|---|
| `RoleCreated` / `RoleUpdated` / `RoleDeleted` | `role.created` / `role.updated` / `role.deleted` | `role`, изменения, `removedAssignments` |
| `RolePermissionsChanged` | `role.permissions_changed` | `role`, `added`, `removed` |
| `RoleAssigned` / `RoleRevoked` | `role.assigned` / `role.revoked` | `subject`, `role`, `context`, `expiresAt`, `attributes` (свои поля — только помеченные плагином как публичные) |
| `PermissionGranted` / `PermissionRevoked` | `permission.granted` / `permission.revoked` | `subject`, `pattern`, `context`, `expiresAt` — по событию на строку |
| `ChangePending` | `change.pending` | `change` (тип, субъект, роль/права, контекст), `reference` плагина |
| `AssignmentExpired` | `assignment.expired` | `subject`, `kind`, `role|pattern`, `context` |
| `AccessStateReset` | `state.reset` | `previousRevision` |
| `RoleDefinitionMissing` | `role.definition_missing` | `role`, `definition` (диагностика) |
| `AccessDecided` | `access.decided` | `subject`, `permission`, `context`, `effect`, `reason`, `restriction` (только `trace_decisions`/`explain`) |

Гарантия доставки: Laravel-события — после commit, at-most-once; durable-след — плагин `azguard/audit` (в
транзакции). Outbox для внешних потребителей — T2.
