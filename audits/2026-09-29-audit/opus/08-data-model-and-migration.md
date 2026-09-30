# 08 — Хранилища, модель данных, правила целостности, события

Решения: [D07](02-decisions.md#d07), [D08](02-decisions.md#d08), [D13](02-decisions.md#d13), [D14](02-decisions.md#d14),
[D22](02-decisions.md#d22), [D24](02-decisions.md#d24), [D28](02-decisions.md#d28), [D34](02-decisions.md#d34),
[D35](02-decisions.md#d35), [D46](02-decisions.md#d46), [D52](02-decisions.md#d52).

Всё, что описано в этом файле, — внутренности **`DatabaseSource`**: только он работает с таблицами AzGuard. Панель без
этого источника таблиц не имеет.

Совместимости с таблицами 0.3 нет ([D01](02-decisions.md#d01)): схема ниже — единственная.

## 1. Хранилища — простыми словами

- В БД хранится только то, что меняется во время работы: **динамические роли** (созданные в админке), **выдачи
  ролей**, **выдачи прав** и — если включён флаг `dynamicPermissions()` — **динамические права**. Права из папки
  панели (enum, роли-классы, `#[GrantedToAll]`, политики) и связи сущностей живут в коде и в данных приложения —
  таблиц AzGuard им не нужно.
- **Хранилище** — «где лежат эти таблицы»: подключение к БД + префикс таблиц + тип колонок для id + классы моделей.
- По умолчанию все панели живут в **общем хранилище** `default` (таблицы `azg_*`) и различаются колонкой `panel`.
- Панель можно вынести: в именованное хранилище из конфига (`DatabaseSource::make()->storage('backoffice')`) или в
  собственное (`->storage(Storage::own(prefix: 'admin_', connection: 'backoffice'))`). Схема та же; миграцию генерирует команда, в неё можно
  дописать свои колонки.
- У каждой панели своя строка «версии состояния»: изменения в админке не сбрасывают кэш кабинета.

```
хранилище default (connection: mysql, prefix: azg_)           хранилище backoffice (connection: backoffice)
┌──────────────────────────────────────────────────┐          ┌──────────────────────────────────────────────┐
│ azg_roles              panel=seller                │          │ azg_roles              panel=admin             │
│ azg_role_grants   panel=seller                │          │ azg_role_grants   panel=admin (+department_id) │
│ azg_permission_grants panel=seller                │          │ azg_permission_grants panel=admin             │
│ azg_panel_state        seller: v41, cabinet: v0    │          │ azg_panel_state        admin: v118             │
└──────────────────────────────────────────────────┘          └──────────────────────────────────────────────┘
панель cabinet: без DatabaseSource — строк в таблицах нет, версия меняется только через touch()
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
  is_super_admin   boolean          NOT NULL DEFAULT false   -- держатель роли — суперадмин (D19)
  meta             json             NULL              -- свои поля без миграции
  created_at, updated_at
  UNIQUE (panel, key)

{p}role_permissions
  id, role_id FK → {p}roles ON DELETE CASCADE, permission varchar(255) ID NOT NULL, created_at
  UNIQUE (role_id, permission)

{p}permissions                                       -- динамические права: только при DatabaseSource::make()->dynamicPermissions()
  id               bigint PK
  panel            varchar(64)  ID  NOT NULL
  name             varchar(255) ID  NOT NULL          -- локальное имя, ≥ 2 сегментов; не совпадает с именем из enum
  label            varchar(191)     NULL
  group            varchar(191)     NULL
  description      text             NULL
  meta             json             NULL
  created_at, updated_at
  UNIQUE (panel, name)

{p}role_grants                                      -- выдача роли из кода ИЛИ из БД
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

{p}permission_grants
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

- Выдача роли ссылается на роль по ключу, а не по id строки: роль из кода не нужно синхронизировать в БД. Удаление
  роли из БД удаляет её выдачи той же транзакцией (в пайплайне изменений, не каскадом БД — чтобы ушли события).
- Плагины со своими таблицами (например `azguard/audit` → `{p}audit_log`) создают их в том же хранилище своей
  миграцией; ядро передаёт им `Storage` (подключение, префикс).
- Размер уникального ключа `{p}permission_grants` на MySQL (ascii) ≈ 721 байт ≪ 3072 — без префиксных индексов и
  особого DDL. Требования к СУБД: PostgreSQL 13+, MySQL 8.0+/MariaDB 10.6+, SQLite 3.35+, при зелёной CI-матрице (Q15).

## 3. Свои модели и колонки

| Способ | Когда | Как |
|---|---|---|
| `meta` (JSON, nullable) | несколько полей, по ним не фильтруют и не строят индексы | поле с `->inMeta()` в `azguardFields()`; каст в модели |
| Колонка в общем хранилище | по полю фильтруют, сортируют или проверяют его ограничениями | миграция хоста добавляет **nullable**-колонку; используют только модели этой панели |
| Колонка в собственном хранилище | у панели своё хранилище | `azguard:storage:migration admin` пишет миграцию в `database/migrations`; хост дописывает колонки |

Почему `meta` устроена именно так (Q22): одна nullable JSON-колонка на таблицу не влияет на идентичность строки и на
индексы; значения типизирует каст модели панели; правила проверки — в `azguardFields()`. Чего `meta` не делает:
быстрых фильтров и сортировок — для этого колонка. Doctor предупреждает, если поле из `decisionFields` лежит в
`meta`.

Проверки при сборке панели: модель наследует базовую; у собственного хранилища таблица и соединение модели (свойства
`$table`/`$connection` или атрибуты Laravel `#[Table]`/`#[Connection]`) совпадают с хранилищем; колонки из `azguardFields()` существуют (doctor сверяет с реальной схемой).

## 4. Правила целостности

Держит пайплайн изменений, проверяет doctor.

| # | Правило | Где держится |
|---|---|---|
| I1 | `role_grants.role` — роль этой панели (из кода или из БД), без `#[NotGrantable]` | шаг «Проверка» |
| I2 | `permission_grants.permission` — локальное имя или шаблон этой панели (без префикса панели); точное имя есть в каталоге; шаблон покрывает ≥ 1 право | шаг «Проверка» |
| I3 | `role_permissions` — только у ролей из БД; только права каталога панели. Решает ли право политика, не проверяется (Q27) | шаг «Проверка» |
| I4 | `context_key = codec(context_type, context_id)`; оба NULL ⇔ `global`; тип принят панелью | шаг «Проверка» + doctor |
| I5 | `expires_at` в будущем на момент записи | шаг «Проверка» |
| I6 | любое изменение строк панели → +1 `panel_state.version` этой панели в той же транзакции | `Storage::mutate()` |
| I7 | никакой голой звёздочки в `permission` | грамматика |
| I8 | свои поля проходят `azguardFields()` и pipes `changing` | шаги «Проверка», «Pipes» |
| I9 | ключ динамической роли не совпадает с ключом статичной; имя динамического права — с именем из enum | шаг «Проверка»; doctor после изменения кода |
| I10 | выдачи ролей, которых больше нет (класс удалён без `#[FormerKeys]`), не дают прав и видны в doctor | `DatabaseSource`, doctor |

## 5. Порядок блокировок и повторы

`Storage::mutate(string $panel, Closure $work, int $attempts = 3)`:

1. `BEGIN` на подключении хранилища;
2. блокировки по порядку: строки `{p}roles` по возрастанию `id` (`FOR UPDATE`) → строки выдач (через уникальные ключи;
   `INSERT … ON CONFLICT` вместо «прочитать, потом вставить») → **последней** — строка `{p}panel_state` этой панели;
3. журнал (плагины) — внутри транзакции перед commit;
4. `COMMIT`; затем Laravel-события (`ShouldDispatchAfterCommit`);
5. повтор на `40001`/`40P01` (PG) и `1213` (MySQL) до `$attempts` раз; события для откаченных попыток не
   отправляются.

Строка версии панели — точка упорядочивания записей **одной панели**; панели друг друга не блокируют.

## 6. Каталог событий

База `AzGuard\Events\AccessEvent` (readonly): `eventId` (ULID), `occurredAt`, `panel`, `actor: ?ActorRef`,
`correlationId: ?string`, `state: StateToken`, `type(): EventType`. Payload — только значения, без Eloquent.

| Событие | `EventType` | Поля |
|---|---|---|
| `RoleCreated` / `RoleUpdated` / `RoleDeleted` | `role.created` / `role.updated` / `role.deleted` | `role`, изменения, `removedGrants` |
| `PermissionCreated` / `PermissionDeleted` | `permission.created` / `permission.deleted` | динамическое право: `permission`, `label`, `removedGrants` |
| `RolePermissionsSynced` | `role.permissions_synced` | `role`, `added`, `removed` |
| `RoleGranted` / `RoleRevoked` | `role.granted` / `role.revoked` | `subject`, `role`, `context`, `expiresAt`, `fields` |
| `PermissionGranted` / `PermissionRevoked` | `permission.granted` / `permission.revoked` | `subject`, `permission`, `context`, `expiresAt`, `fields` — по событию на строку |
| `GrantExpired` | `grant.expired` | `subject`, `kind`, `role` или `permission`, `context` |
| `PanelStateTouched` | `panel.touched` | `previousVersion`, причина (`touch()`, `azguard:state:reset`) |
| `AccessDecided` | `access.decided` | `subject`, `permission`, `context`, `effect`, `reason`, `component` (только при трассировке) |

Доставка: Laravel-события — после commit, не более одного раза; надёжный след — плагин `azguard/audit` (в
транзакции). Outbox для внешних потребителей — после 1.0.
