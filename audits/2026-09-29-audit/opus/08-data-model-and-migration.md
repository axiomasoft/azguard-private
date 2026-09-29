# 08 — Модель данных, инварианты, миграция

Решения: [D07](02-decisions.md#d07), [D08](02-decisions.md#d08), [D13](02-decisions.md#d13), [D14](02-decisions.md#d14),
[D19](02-decisions.md#d19), [D22](02-decisions.md#d22), [D24](02-decisions.md#d24), [D28](02-decisions.md#d28),
[D34](02-decisions.md#d34), [D35](02-decisions.md#d35).

## 1. Схема 0.4 (префикс `azg_`)

Обозначения: `HK` — колонка ключа хоста по `ids.host_keys` (`string` → `varchar(64)`, `bigint`, `uuid`, `ulid`);
`ID` — ASCII-идентификатор: на MySQL `charset ascii, collation ascii_bin`, на PostgreSQL/SQLite — обычный
`varchar` (грамматика D07/D18 гарантирует ASCII; побайтное сравнение без зависимости от collation, индексы
укладываются в лимит InnoDB 3072 байт).

```
azg_roles
  id               bigint PK
  realm            varchar(64)  ID  NOT NULL
  key              varchar(64)  ID  NOT NULL
  label            varchar(191)     NULL
  description      text             NULL
  origin           varchar(16)      NOT NULL          -- code | database | system
  definition       varchar(255) ID  NULL              -- FQCN RoleDefinition (только code)
  is_superadmin    boolean          NOT NULL DEFAULT false
  rank             integer          NOT NULL DEFAULT 0
  created_at, updated_at
  UNIQUE (realm, key)                 azg_roles_identity_uq
  UNIQUE (definition)                 azg_roles_definition_uq        -- NULL различны: нормально

azg_role_permissions                                  -- только для origin = database
  id               bigint PK
  role_id          bigint FK → azg_roles ON DELETE CASCADE
  permission       varchar(255) ID  NOT NULL          -- ключ или шаблон realm роли
  created_at
  UNIQUE (role_id, permission)        azg_role_permissions_uq

azg_role_assignments
  id               bigint PK
  role_id          bigint FK → azg_roles ON DELETE CASCADE
  realm            varchar(64)  ID  NOT NULL          -- денормализовано из роли (роль не меняет realm)
  subject_type     varchar(128) ID  NOT NULL
  subject_id       HK               NOT NULL
  context_key      varchar(200) ID  NOT NULL          -- 'global' | '{type}:{id}'
  context_type     varchar(128) ID  NULL
  context_id       HK               NULL
  expires_at       timestamp        NULL
  granted_by_type  varchar(128) ID  NULL
  granted_by_id    varchar(64)  ID  NULL
  reason           varchar(255)     NULL
  created_at, updated_at
  UNIQUE (role_id, subject_type, subject_id, context_key)   azg_role_assignments_identity_uq
  INDEX  (subject_type, subject_id, realm, context_key)     azg_role_assignments_lookup_idx
  INDEX  (context_type, context_id, realm)                  azg_role_assignments_context_idx
  INDEX  (expires_at)                                        azg_role_assignments_expiry_idx

azg_grants
  id               bigint PK
  realm            varchar(64)  ID  NOT NULL
  permission       varchar(255) ID  NOT NULL          -- ключ или шаблон
  subject_type, subject_id, context_key, context_type, context_id, expires_at,
  granted_by_type, granted_by_id, reason, created_at, updated_at     -- как в azg_role_assignments
  UNIQUE (subject_type, subject_id, permission, context_key)         azg_grants_identity_uq
  INDEX  (subject_type, subject_id, realm, context_key)              azg_grants_lookup_idx
  INDEX  (context_type, context_id, realm)                           azg_grants_context_idx
  INDEX  (expires_at)                                                 azg_grants_expiry_idx

azg_state
  id               smallint PK (= 1)
  revision         bigint NOT NULL
  schema           json   NOT NULL                   -- {version, table_prefix, host_keys, connection, upgraded_from}
  updated_at       timestamp

azg_audit_log                                        -- создаётся всегда, пишется при features.audit
  event_id         char(26) PK                       -- ULID = AccessEvent::eventId
  type             varchar(64)  NOT NULL             -- EventType
  occurred_at      timestamp(6) NOT NULL
  actor_type, actor_id, actor_reason
  subject_type, subject_id                           NULL
  realm, context_key                                 NULL
  state_revision   bigint NOT NULL
  correlation_id   varchar(64) NULL
  payload          json NOT NULL
  INDEX (subject_type, subject_id, occurred_at), INDEX (occurred_at)
```

Размер уникального ключа `azg_grants` на MySQL (ascii, 1 байт/символ + 2 байта длины): `subject_type` 130 +
`subject_id` 66 (`HK=string` тоже ASCII) + `permission` 257 + `context_key` 202 ≈ 655 байт ≪ 3072 — никаких
префиксных индексов и driver-специфичного DDL. Требования к СУБД
**снижаются**: PostgreSQL 13+, MySQL 8.0+/MariaDB 10.6+, SQLite 3.35+ (`NULLS NOT DISTINCT` и выражения-маркеры
больше не нужны, т. к. в идентичности нет NULL).

## 2. Инварианты (держит `AccessManager`, проверяет doctor)

| # | Инвариант | Где держится |
|---|---|---|
| I1 | `assignment.realm = role.realm`; `role.realm` не меняется | `AssignRoleAction`, `UpdateRoleAction` запрещает смену realm |
| I2 | superadmin-роли назначаются только с `context_key = 'global'`; `*:superadmin` — единственная роль realm `*` | `AssignRoleAction`, `AssignPlatformSuperadminAction` |
| I3 | `grant.permission` — ключ или шаблон `grant.realm`; точный ключ есть в каталоге; шаблон покрывает ≥ 1 ключ каталога | `IssueGrantAction` |
| I4 | `role_permissions` — только у `origin = database`, шаблоны realm роли | `SetRolePermissionsAction`; doctor для code-ролей |
| I5 | `context_key = codec(context_type, context_id)`; оба NULL ⇔ `global`; тип контекста принят realm | Actions + doctor |
| I6 | `expires_at` в будущем на момент записи | Actions |
| I7 | любое изменение строк AzGuard → +1 `azg_state.revision` в той же транзакции | `AzGuardDatabase::mutate()` |
| I8 | `definition` разрешается в класс `RoleDefinition` зарегистрированного realm (иначе роль пустая + doctor error, не исключение на чтении) | `RolesSource`, doctor |
| I9 | никакого `*` без realm в `permission` | грамматика |

## 3. Порядок блокировок и ретраи

`AzGuardDatabase::mutate(Closure $work, int $attempts = 3)`:

1. `BEGIN` на соединении AzGuard;
2. блокировки в каноническом порядке: строки `azg_roles` по возрастанию `id` (`FOR UPDATE`) → строки назначений/
   грантов (через уникальные ключи; `INSERT … ON CONFLICT DO NOTHING/UPDATE` вместо «прочитать-потом-вставить») →
   **последней** — `azg_state` (`FOR UPDATE`, bump);
3. события копятся в `EventRecorder`; audit-строки пишутся перед bump;
4. `COMMIT`; затем диспатч Laravel-событий (after-commit);
5. повтор на `40001`/`40P01` (PG) и `1213` (MySQL) до `$attempts` раз; события не диспатчатся для откаченных попыток.

Строка `azg_state` — точка сериализации записей. Это сознательная цена «одна ревизия = одно состояние»; бюджет
конкуренции измеряется бенчмарком (D44), раздельные ревизии — T2 (D21).

## 4. Чтение (встроенные источники)

```sql
-- RolesSource: назначения субъекта в realm для набора контекстов (одна выборка)
SELECT a.role_id, a.context_key, a.expires_at, r.key, r.origin, r.definition, r.is_superadmin
FROM azg_role_assignments a JOIN azg_roles r ON r.id = a.role_id
WHERE a.subject_type = ? AND a.subject_id = ? AND a.realm = ?
  AND a.context_key IN (?, ?) AND (a.expires_at IS NULL OR a.expires_at > ?);
-- права DB-ролей: одна выборка по списку role_id (code-роли — из реестра в памяти)
SELECT role_id, permission FROM azg_role_permissions WHERE role_id IN (…);

-- GrantsSource
SELECT permission, context_key, expires_at, id FROM azg_grants
WHERE subject_type = ? AND subject_id = ? AND realm = ? AND context_key IN (?, ?)
  AND (expires_at IS NULL OR expires_at > ?);
```

`?` для `now` передаётся из `SourceReadOptions::now` (одно значение на оценку — детерминизм `decideMany`).
При `database.reads = primary` запросы идут через write-PDO.

## 5. Upgrade 0.3.x → 0.4.0

Одна миграция `2026_10_01_000200_upgrade_azguard_03_to_04` (no-op на свежей установке) + команда
`azguard:upgrade --dry-run|--execute [--realm-for-orphans=app]`, которую миграция вызывает в режиме execute.
Старые таблицы **переименовываются** в `*_legacy_03`, удаляются только `azguard:upgrade --drop-legacy`.

**Предусловия (preflight, только чтение; отказ — до любого DDL):** хост заменил `PanelProvider` на `RealmProvider`
(id совпадают или заданы в `--realm-map=old:new`); все id проходят грамматику realm; role-классы реализуют
`RoleDefinition`; типы контекстов не содержат `:`; ключи прав проходят грамматику 0.4 (нижний регистр).

| Источник 0.3 | Результат 0.4 | Отчёт/поведение |
|---|---|---|
| `roles` `super-admin` (класс `SuperAdminRole`) | `*:superadmin` (`origin=system`, `is_superadmin`) | назначения переносятся |
| `roles` `panel:name` с `class_name` | code-роль `(panel, definition::key())`, `definition = class_name` | класс не найден → `database`-роль без прав + **error** в отчёте |
| code-роль, чьи `permissions()` содержали `*` | `is_superadmin = true` **в своём realm** | **изменение поведения**: в 0.3 такая роль действовала во всех панелях (N01) — перечисляется в отчёте |
| DB-роль (`class_name` NULL), права в одной панели | `database`-роль `(panel, slug(name))` | |
| DB-роль с правами в нескольких панелях | по роли на каждый realm, назначения дублируются | перечисляется |
| DB-роль без прав | realm из `--realm-for-orphans` | без флага — preflight-ошибка |
| `role_permissions` `*` | `is_superadmin = true` у роли этого realm | |
| `role_permissions` ключ вне каталога | переносится как есть | warning; doctor покажет «мёртвые» права |
| `model_has_roles` | `azg_role_assignments` (`global`) | |
| `model_has_scopes` с `role_id` | `azg_role_assignments` с контекстом `(scope_entity_type, scope_entity_id)` | `panel_id` ≠ realm роли → строка пропускается и перечисляется; `panel_id NULL` («любая панель») → realm роли |
| `model_has_scopes` без `role_id` | не переносится | перечисляется (в 0.3 такие строки только фильтровали запросы) |
| `az_direct_grants` | `azg_grants` (`global`) | `*` → назначение `{realm}:superadmin` (DB-роль, создаётся) с тем же `expires_at` |
| `az_guard_context_roles` | `azg_grants` с контекстом | |
| `az_guard_permission_state.revision` | `azg_state.revision + 1` | кэш старых ключей недействителен (v3-ключи) |

Выполнение: создание новых таблиц → копирование одной транзакцией (PG — вместе с DDL; MySQL — DDL отдельно,
копирование транзакционно, идемпотентно по `azg_state.schema.upgraded_from`) → переименование старых таблиц.
`down()` — восстановление из `*_legacy_03`, пока они не удалены.

**Проверка:** V40–V42 в [14-verification.md](14-verification.md) — фикстура 0.3 со всеми строками таблицы выше,
сравнение решений до/после на матрице `(subject, key, context)`; допустимые расхождения — только строки отчёта с
пометкой «изменение поведения».

## 6. Каталог событий

База `AzGuard\Events\AccessEvent` (readonly): `eventId` (ULID), `occurredAt`, `actor: ActorRef`, `correlationId: ?string`,
`stateRevision: int`, `type(): EventType`. Payload — только значения:

| Событие | `EventType` | Поля |
|---|---|---|
| `RoleCreated` | `role.created` | `role: RoleKey`, `origin`, `label`, `rank`, `isSuperadmin` |
| `RoleUpdated` | `role.updated` | `role`, `changes: array<string, array{from, to}>` |
| `RoleDeleted` | `role.deleted` | `role`, `removedAssignments: int` |
| `RolePermissionsChanged` | `role.permissions_changed` | `role`, `added: list<string>`, `removed: list<string>` |
| `RoleAssigned` | `role.assigned` | `subject: SubjectRef`, `role`, `context: ContextRef`, `expiresAt` |
| `RoleUnassigned` | `role.unassigned` | `subject`, `role`, `context` |
| `GrantIssued` | `grant.issued` | `subject`, `pattern`, `context`, `expiresAt`, `replacedExpiresAt` |
| `GrantRevoked` | `grant.revoked` | `subject`, `pattern`, `context` — **по событию на строку** (не `*` как «всё») |
| `AssignmentExpired` | `assignment.expired` | `subject`, `kind: role|grant`, `role|pattern`, `context` (при prune) |
| `AuthorizationStateReset` | `state.reset` | `previousRevision` |
| `RoleDefinitionMissing` | `role.definition_missing` | `role`, `definition` (диагностика, не аудит) |
| `AccessDecided` | `access.decided` | `subject`, `permission`, `context`, `effect`, `reason`, `constraint` (только при `trace_decisions`/`explain`) |

Гарантия доставки: Laravel-события — at-most-once после commit (процесс может упасть между commit и dispatch);
**durable**-след — `azg_audit_log` (в транзакции) при `features.audit`. Outbox для внешних потребителей — T2 (D21).
