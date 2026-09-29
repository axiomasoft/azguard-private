# 08 — Хранилища, модель данных, правила целостности, события

Решения: [D07](02-decisions.md#d07), [D08](02-decisions.md#d08), [D13](02-decisions.md#d13), [D14](02-decisions.md#d14),
[D22](02-decisions.md#d22), [D24](02-decisions.md#d24), [D28](02-decisions.md#d28), [D34](02-decisions.md#d34),
[D35](02-decisions.md#d35), [D46](02-decisions.md#d46).

Совместимости с таблицами 0.3 нет ([D01](02-decisions.md#d01)): схема ниже — единственная.

## 1. Хранилища — простыми словами

- В БД хранится только то, что меняется во время работы: **роли, созданные в админке**, **назначения ролей** и
  **прямые права**. Права всем, роли из кода, политики и связи сущностей живут в коде и в данных приложения —
  таблиц AzGuard им не нужно.
- **Хранилище** — «где лежат эти таблицы»: подключение к БД + префикс таблиц + тип колонок для id + классы моделей.
- По умолчанию все панели живут в **общем хранилище** `default` (таблицы `azg_*`) и различаются колонкой `panel`.
- Панель можно вынести: в именованное хранилище из конфига (`->storage('backoffice')`) или в собственное
  (`Storage::own(prefix: 'admin_', connection: 'backoffice')`). Схема та же; миграцию генерирует команда, в неё можно
  дописать свои колонки.
- У каждой панели своя строка «версии состояния»: изменения в админке не сбрасывают кэш кабинета.

```
хранилище default (connection: mysql, prefix: azg_)           хранилище backoffice (connection: backoffice)
┌──────────────────────────────────────────────────┐          ┌──────────────────────────────────────────────┐
│ azg_roles              panel=seller                │          │ azg_roles              panel=admin             │
│ azg_role_assignments   panel=seller                │          │ azg_role_assignments   panel=admin (+department_id) │
│ azg_direct_permissions panel=seller                │          │ azg_direct_permissions panel=admin             │
│ azg_panel_state        seller: v41, cabinet: v0    │          │ azg_panel_state        admin: v118             │
└──────────────────────────────────────────────────┘          └──────────────────────────────────────────────┘
панель cabinet: только код и политики — строк в таблицах нет, версия меняется только через touch()
```

## 2. Схема хранилища

Одинакова для любого хранилища; `{p}` — префикс. `HK` — колонка ключа хоста по `host_keys` (`string` →
`varchar(64)`, `bigint`, `uuid`, `ulid`). `ID` — ASCII-идентификатор (MySQL: `charset ascii, collation ascii_bin`;
PG/SQLite — обычный `varchar`; грамматика D07/D18 гарантирует ASCII).

```
{p}roles                                             -- только роли, созданные во время работы
  id               bigint PK
  panel            varchar(64)  ID  NOT NULL
  key              varchar(64)  ID  NOT NULL
  label            varchar(191)     NULL
  description      text             NULL
  meta             json             NULL              -- свои поля без миграции
  created_at, updated_at
  UNIQUE (panel, key)

{p}role_permissions
  id, role_id FK → {p}roles ON DELETE CASCADE, permission varchar(255) ID NOT NULL, created_at
  UNIQUE (role_id, permission)

{p}role_assignments                                  -- назначение роли из кода ИЛИ из БД
  id               bigint PK
  panel            varchar(64)  ID  NOT NULL
  role             varchar(64)  ID  NOT NULL          -- ключ роли; роль из кода в {p}roles не копируется
  subject_type     varchar(128) ID  NOT NULL
  subject_id       HK               NOT NULL
  context_key      varchar(200) ID  NOT NULL          -- 'global' | '{type}:{id}'
  context_type     varchar(128) ID  NULL
  context_id       HK               NULL
  expires_at       timestamp        NULL
  actor_type       varchar(128) ID  NULL              -- кто выдал, если известен
  actor_id         varchar(64)  ID  NULL
  meta             json             NULL
  created_at, updated_at
  UNIQUE (panel, role, subject_type, subject_id, context_key)
  INDEX  (panel, subject_type, subject_id, context_key)
  INDEX  (panel, context_type, context_id)
  INDEX  (expires_at)

{p}direct_permissions
  id, panel, permission varchar(255) ID NOT NULL, subject_type, subject_id, context_key, context_type, context_id,
  expires_at, actor_type, actor_id, meta, created_at, updated_at                    -- как выше
  UNIQUE (panel, subject_type, subject_id, permission, context_key)
  INDEX  (panel, subject_type, subject_id, context_key)
  INDEX  (panel, context_type, context_id)
  INDEX  (expires_at)

{p}panel_state
  panel            varchar(64) ID PK
  version          bigint NOT NULL
  updated_at       timestamp

{p}storage_state                                     -- одна строка на хранилище
  id               smallint PK (= 1)
  schema           json NOT NULL                     -- {version, table_prefix, host_keys, connection}
```

- Назначение ссылается на роль по ключу, а не по id строки: роль из кода не нужно синхронизировать в БД. Удаление
  роли из БД удаляет её назначения той же транзакцией (в пайплайне изменений, не каскадом БД — чтобы ушли события).
- Плагины со своими таблицами (например `azguard/audit` → `{p}audit_log`) создают их в том же хранилище своей
  миграцией; ядро передаёт им `Storage` (подключение, префикс).
- Размер уникального ключа `{p}direct_permissions` на MySQL (ascii) ≈ 721 байт ≪ 3072 — без префиксных индексов и
  особого DDL. Требования к СУБД: PostgreSQL 13+, MySQL 8.0+/MariaDB 10.6+, SQLite 3.35+, при зелёной CI-матрице (Q15).

## 3. Свои модели и колонки

| Способ | Когда | Как |
|---|---|---|
| `meta` (JSON, nullable) | несколько полей, по ним не фильтруют и не строят индексы | поле с `->inMeta()` в `azguardFields()`; каст в модели панели |
| Колонка в общем хранилище | по полю фильтруют, сортируют или проверяют его ограничениями | миграция хоста добавляет **nullable**-колонку; используют только модели этой панели |
| Колонка в собственном хранилище | у панели своё хранилище | `azguard:storage:migration admin` пишет миграцию в `database/migrations`; хост дописывает колонки |

Почему `meta` устроена именно так (Q22): одна nullable JSON-колонка на таблицу не влияет на идентичность строки и на
индексы; значения типизирует каст модели панели; правила проверки — в `azguardFields()`. Чего `meta` не делает:
быстрых фильтров и сортировок — для этого колонка. Doctor предупреждает, если поле из `decisionFields` лежит в
`meta`.

Проверки при сборке панели: модель наследует базовую; у собственного хранилища `$table/$connection` модели совпадают
с хранилищем; колонки из `azguardFields()` существуют (doctor сверяет с реальной схемой).

## 4. Правила целостности

Держит пайплайн изменений, проверяет doctor.

| # | Правило | Где держится |
|---|---|---|
| I1 | `role_assignments.role` — роль этой панели (из кода или из БД), назначаемая вручную | шаг «Проверка» |
| I2 | `direct_permissions.permission` — имя или шаблон этой панели; точное имя есть в каталоге и `assignable`; шаблон покрывает ≥ 1 выдаваемое право | шаг «Проверка» |
| I3 | `role_permissions` — только у ролей из БД; только выдаваемые права панели | шаг «Проверка» |
| I4 | `context_key = codec(context_type, context_id)`; оба NULL ⇔ `global`; тип принят панелью | шаг «Проверка» + doctor |
| I5 | `expires_at` в будущем на момент записи | шаг «Проверка» |
| I6 | любое изменение строк панели → +1 `panel_state.version` этой панели в той же транзакции | `Storage::mutate()` |
| I7 | никакой голой звёздочки в `permission` | грамматика |
| I8 | свои поля проходят `azguardFields()` и хуки `changing` | шаги «Проверка», «Хуки» |
| I9 | ключ роли из БД не совпадает с ключом роли из кода | шаг «Проверка»; doctor после изменения кода |
| I10 | назначения ролей, которых больше нет (класс удалён без `formerKeys`), не дают прав и видны в doctor | механика `azguard/database`, doctor |

## 5. Порядок блокировок и повторы

`Storage::mutate(string $panel, Closure $work, int $attempts = 3)`:

1. `BEGIN` на подключении хранилища;
2. блокировки по порядку: строки `{p}roles` по возрастанию `id` (`FOR UPDATE`) → строки выдач (через уникальные ключи;
   `INSERT … ON CONFLICT` вместо «прочитать, потом вставить») → **последней** — строка `{p}panel_state` этой панели;
3. журнал (плагины) — внутри транзакции перед commit;
4. `COMMIT`; затем события и хуки `changed`;
5. повтор на `40001`/`40P01` (PG) и `1213` (MySQL) до `$attempts` раз; события для откаченных попыток не
   отправляются.

Строка версии панели — точка упорядочивания записей **одной панели**; панели друг друга не блокируют.

## 6. Каталог событий

База `AzGuard\Events\AccessEvent` (readonly): `eventId` (ULID), `occurredAt`, `panel`, `actor: ?ActorRef`,
`correlationId: ?string`, `state: StateToken`, `type(): EventType`. Payload — только значения, без Eloquent.

| Событие | `EventType` | Поля |
|---|---|---|
| `RoleCreated` / `RoleUpdated` / `RoleDeleted` | `role.created` / `role.updated` / `role.deleted` | `role`, изменения, `removedAssignments` |
| `RolePermissionsSynced` | `role.permissions_synced` | `role`, `added`, `removed` |
| `RoleAssigned` / `RoleRemoved` | `role.assigned` / `role.removed` | `subject`, `role`, `context`, `expiresAt`, `fields` |
| `PermissionGiven` / `PermissionRevoked` | `permission.given` / `permission.revoked` | `subject`, `permission`, `context`, `expiresAt`, `fields` — по событию на строку |
| `AssignmentExpired` | `assignment.expired` | `subject`, `kind`, `role` или `permission`, `context` |
| `PanelStateTouched` | `panel.touched` | `previousVersion`, причина (`touch()`, `azguard:state:reset`) |
| `AccessDecided` | `access.decided` | `subject`, `permission`, `context`, `effect`, `reason`, `component` (только при трассировке) |

Доставка: Laravel-события — после commit, не более одного раза; надёжный след — плагин `azguard/audit` (в
транзакции). Outbox для внешних потребителей — после 1.0.
