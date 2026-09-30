# 08 — Хранилища, модель данных, правила целостности, события

Нормативно для целевой 1.0; реализация 0.3 этих контрактов ещё не обеспечивает.
Решения: D07–D08, D13–D16, D22–D25, D28, D34–D35, D46, D59–D83 из [журнала](02-decisions.md).
Самый сложный пример — [CRM](16-crm-and-workflows.md). Проверки — [14](14-verification.md).

## 1. Что принадлежит AzGuard

`DatabaseSource` хранит назначения PHP-ролей/прав и opt-in каталог дополнительных динамических прав. Организации, проекты, клиенты,
членство в организациях и связи с внешними системами принадлежат приложению или его пакетам.
AzGuard хранит **ссылку на тенанта в идентичности каждой записи доступа**, а не случайный `tenant_id` в `meta`.
Панель без `DatabaseSource` может использовать тенанты и контексты через те же контракты.

Хранилище = стабильный id + подключение + префикс + тип ключей + модели. Панель использует одно хранилище;
разные панели могут разделять таблицы (`panel`) или использовать разные подключения. Пара `(connection, prefix)`
регистрируется один раз: разные имена одного физического хранилища — ошибка, а не две независимые версии кэша.
Роутинг подключения по текущему тенанту не входит в 1.0: он требует отдельного контракта физической идентичности
хранилища. Тенантная изоляция в 1.0 поддерживается в общей схеме; отдельные хранилища можно задавать панелям.

## 2. Схема хранилища

`{p}` — префикс. `ID(n)` — ASCII varchar длины n с точным побайтовым сравнением;
MySQL/MariaDB используют бинарное сравнение, без нечувствительности к регистру и без игнорирования хвостовых пробелов.
Идентификаторы не содержат пробелов/control bytes. PG использует детерминированное сравнение, SQLite — BINARY.
`HK` — тип ключей хоста (`string` = ASCII varchar(64), bigint, uuid, ulid).
Кодек проверяет, что представление допустимо для выбранного HK без потери информации (D07–D08).

```
{p}permissions                             -- таблица создаётся схемой, возможность записи включается флагом
  id                 bigint PK
  panel              ID(64)  NOT NULL
  tenant_key         ID(200) NOT NULL
  tenant_type        ID(128) NULL
  tenant_id          HK NULL
  name               ID(255) NOT NULL      -- точное локальное имя, >= 2 сегментов
  label, group       varchar(191) NULL
  description        text NULL
  meta               json NULL
  created_at, updated_at
  UNIQUE (panel, tenant_key, name)
  CHECK tenant_type/id обе NULL <=> tenant_key = 'global'

{p}role_grants
  id                 bigint PK
  panel              ID(64)  NOT NULL
  tenant_key         ID(200) NOT NULL
  tenant_type        ID(128) NULL
  tenant_id          HK NULL
  role               ID(64)  NOT NULL
  subject_type       ID(128) NOT NULL
  subject_id         HK NOT NULL
  context_key        ID(200) NOT NULL      -- global | type:id; global ВНУТРИ этого tenant_key
  context_type       ID(128) NULL
  context_id         HK NULL
  origin             ID(128) NOT NULL DEFAULT 'manual'
  expires_at         timestamp NULL       -- абсолютное UTC-время
  actor_type         ID(128) NULL
  actor_id           HK NULL
  actor_reason       text NULL
  meta               json NULL
  created_at, updated_at
  UNIQUE (panel, tenant_key, role, subject_type, subject_id, context_key, origin)
  INDEX (panel, tenant_key, subject_type, subject_id, context_key)
  INDEX (panel, tenant_key, context_type, context_id)
  INDEX (panel, tenant_key, origin)
  INDEX (expires_at)
  CHECK type/id обе NULL <=> соответствующий key = 'global' (для tenant и context)

{p}permission_grants
  как role_grants, но permission ID(255) вместо role
  UNIQUE (panel, tenant_key, permission, subject_type, subject_id, context_key, origin)
  те же индексы и CHECK

{p}panel_state
  panel              ID(64) PK
  version            bigint NOT NULL
  incarnation        ID(26) NOT NULL       -- новая при reset/restore; старый кэш не возрождается
  updated_at         timestamp

{p}storage_state
  id                 smallint PK (= 1)
  schema             json NOT NULL        -- version, identity_codec, storage_id, prefix, host_keys
```

Полный ключ строки выдачи — **панель + тенант + субъект + роль/право + контекст + origin**.
Назначения одного субъекта с одинаковыми локальными id проектов в разных организациях остаются разными.
Роль существует в PHP-каталоге панели, один stable key связан с одним BaseRole class. В разных tenant
назначаются разные экземпляры доступа к одной definition; состав роли/context filters не редактируется в БД.
В role_grants нет FK на PHP-класс. Mutation проверяет каталог/current build fingerprint под state lock.
Удалённый/неизвестный role key не даёт прав, но сохранённая выдача доступна cleanup/revoke; автоматический fallback
по похожему имени запрещён. FormerKeys + явная scoped grant migration поддерживают code rename (19 §6).

Точная dynamic permission существует только в выбранном tenant и только Grants authority. Static enum names
зарезервированы во всех tenants. Definition и назначения — разные строки: enum права также назначаются через БД,
без копии в permissions. При удалении dynamic action удаляются её exact direct grants этого tenant; wildcard
назначения остаются, но matcher не считает unknown action существующим. Роли-паттерны из PHP могут охватывать
новые dynamic actions только осознанно; code role composition не переписывается SQL операцией.

Порядок unique columns не меняет смысл. Максимальная длина строкового unique permission grant — примерно
1039 ASCII bytes (64+200+255+128+64+200+128), до служебных накладных расходов.
Это **бюджет**, а не доказательство переносимости DDL: генератор проверяется на PG/MySQL/MariaDB/SQLite;
InnoDB page size и collation входят в отчёт. Префиксные unique indexes запрещены.
Версии PG 13+, MySQL 8.0+, MariaDB 10.6+, SQLite 3.35+ — кандидаты совместимости, поддержка объявляется после матрицы.

## 3. Свои модели и колонки

`DatabaseSource::models(...)` задаёт наследников базовых моделей. Свои поля описаны `azguardFields()`:
обычная колонка — для индексации и FK; `meta` — для небольших дополнительных значений.
`tenant_key`, `panel`, ссылки, `origin`, версия и системные признаки не принимаются через `fields:`.
Изменение срока/полей выдачи — отдельная операция, не произвольный `fill()->save()`.

`Field::model('department_id', Department::class)` валидирует не только существование отдела, но и его
принадлежность выбранному тенанту через directory/validator приложения. То же для всех model-полей и поисков UI.
Поля, участвующие в доступе, — в `decisionFields`; условие **одной выдачи** проверяется `GrantCondition`
до объединения (§4), общий запрет панели — `Restriction`. Формы используют те же серверные правила.
В общем хранилище дополнительные колонки nullable; обязательность задаётся моделью/схемой конкретной панели.
Модель и хранилище сверяют connection/table. Публичное чтение — через scoped read API, а не произвольный builder
из Filament. Записи базовых моделей в обход пайплайна запрещены во всех окружениях; raw SQL вне контракта.

## 4. Правила целостности

| # | Правило | Где держится |
|---|---|---|
| I1 | Каждый read/write имеет явные panel и tenant_key; отсутствие tenant при required — ошибка | scope resolver, scoped repositories |
| I2 | Context принадлежит выбранному tenant; resource принадлежит tenant и связан с выбранным context | `ContextDefinition::tenantOf`, `ResourceScopeResolver`; без resolver — отказ |
| I3 | Субъект принят панелью; membership tenant проверяется при доступе и, если настроено, при выдаче | tenant policy и change validators |
| I4 | Role существует в static definitions или в этом tenant; configured binding контекста разрешён ролью; context_required запрещает tenant-wide выдачу; Assignment eligibility проверяется | финальная валидация под блокировкой |
| I5 | Exact permission существует в scoped catalog; pattern покрывает >= 1 текущее право; нет голой звёздочки | грамматика, каталог |
| I6 | tenant/context key соответствует type/id; type зарегистрирован, id каноничен для HK | кодек + CHECK формы NULL + doctor; равенство key проверяет API |
| I7 | expires_at > now при выдаче; при чтении expires_at > decisionNow, независимо от TTL кэша | запись и каждый доступ |
| I8 | Effective mutation -> +1 version этой панели в той же транзакции; no-op -> ни bump, ни событие | Storage::mutate |
| I9 | Финальный Change после всех pipes валиден; pipe не меняет panel, tenant, subject, actor и origin | change pipeline |
| I10 | origin определяет владельца вклада; sync/revoke одного origin не затрагивает другой | write API |
| I11 | Reserved key, collision, удалённый class/context alias не переназначаются молча | сборка, deployment validation, doctor |
| I12 | Срок и поля применяются к одной grant; нельзя взять weekday одной и department другой | GrantCondition, exact query branch |

Полиморфные ссылки не получают полноценный FK на таблицы приложения. Поэтому I2 нельзя объявлять
«защищённым БД» для произвольной внешней модели. Хост добавляет составные FK для своих обычных таблиц
(например `(organization_id, project_id)`); SPI обеспечивает runtime validation для остальных.
Deactivation/смена user city/role fields меняет context eligibility по declared freshness, не требует физического удаления grants.
Revoke inactive/expired/orphan rows доступен actor по stored panel/tenant/origin; runtime filter не закрывает cleanup.
Удаление тенанта/проекта/субъекта инвалидирует ссылки и кэши зависимых источников; dangling ref не даёт доступа.
Повторное использование id для другого объекта запрещено без очистки выдач/смены identity namespace.
Перенос проекта в другую организацию — revoke старых scoped grants и явное новое назначение, без автоматического переноса.

## 5. Порядок блокировок и повторы

`Storage::mutate(panel, work, attempts: 3)` выполняет:

1. BEGIN на подключении хранилища. Создать отсутствующую `panel_state` идемпотентно, затем **первой**
   заблокировать эту строку (`FOR UPDATE`; SQLite — write transaction с ограниченным busy retry).
2. Выполнить pipes, разрешённые повторяемые validators и финальную валидацию Change **в транзакции**.
   Прочитать role/catalog под той же защитой. Далее роли по id, затем выдачи по полному unique key.
3. Записать изменения (`upsert` после семантического сравнения), журнал; при effective change увеличить version
   один раз на всю внешнюю mutation. Snapshot роли для `expectedFingerprint` сравнить здесь, а не до BEGIN.
4. При внешнем COMMIT опубликовать новую версию в request memo и отправить события. Nested mutation использует
   savepoint; rollback outer transaction отменяет строки, bump, журнал и after-commit callbacks.
5. Deadlock/serialization/busy retry ограничен; исчерпание -> исключение, без события об успехе.

Первый lock состояния закрывает `deleteRole` vs `grantRole`: после удаления нельзя создать сиротскую выдачу
между предварительной проверкой роли и записью. Цена — сериализация записей одной панели, включая разные тенанты.
Это сознательный исходный вариант; partition state по tenant допустим после замеров и отдельного решения о
shared static definitions. Другие панели имеют другие state rows; multi-panel bulk сначала сортирует lock keys
по `(storage_id, panel)`. Атомарная операция по разным подключениям **не обещается**; API отказывает в ней.

Pipes могут дополнять срок/поля или отменять операцию. Их внешние побочные эффекты, HTTP и отправка сообщений
внутри retry запрещены; реакция — после commit. `ShouldDispatchAfterCommit` не делает событие долговечным.
Возврат из вложенной mutation ещё не означает внешний commit: ChangeResult отмечает pending commit,
а его tentative state нельзя использовать для общего кэша. Записи приложения и AzGuard атомарны только
на одном connection и в одной внешней транзакции; проверка принадлежности внешних объектов сама по себе не
защищает от их конкурентного переноса — хост использует locks/revisions по [09 §14](09-authorization-semantics.md#14-проверка-и-защищаемое-действие).

## 6. Каталог событий

`AccessEvent` readonly: `eventId` (ULID), `occurredAt` (UTC), `panel`, `tenant: TenantRef`, `actor: ?ActorRef`,
`correlationId`, `state: CodeStateToken|StateToken`, `type(): EventType`. Payload — значения, не Eloquent-модели.

| Событие | EventType | Данные |
|---|---|---|
| PermissionCreated / Updated / Deleted | permission.created / updated / deleted | permission, поля, removed grants |
| RoleGranted / Revoked / RoleGrantUpdated | role.granted / revoked / grant_updated | subject, role, context, origin, expiresAt, fields |
| PermissionGranted / Revoked / PermissionGrantUpdated | permission.granted / revoked / grant_updated | subject, permission, context, origin, expiresAt, fields |
| GrantExpired | grant.expired | subject, kind, role/permission, context, origin |
| PanelStateTouched | panel.touched | previousVersion, reason |
| AccessDecided | access.decided | subject, permission, context, effect, reason, component; только tracing |

Нет изменения -> нет события. После root commit событие best effort: падение процесса может его потерять;
listener exception после commit не отменяет mutation и не провоцирует повторную выдачу. Не обещаются exactly-once,
порядок доставки между процессами и доставка во внешнюю систему. Audit plugin пишет долговечный след внутри
той же транзакции. Для обязательной внешней доставки интеграция использует собственный transactional outbox
и idempotent consumer; встроенный outbox остаётся за границами 1.0.

Context definitions, filters и состав ролей хранятся в PHP и меняются code review/deploy с новым build fingerprint.
Здесь нет roles/role permissions/context config таблиц. Собственные поля размещаются на RoleGrant/PermissionGrant,
не на definition роли; они валидируются typed field schema и GrantCondition. State version меняется при назначении/
отзыве/обновлении grant или dynamic action; build fingerprint — при code change. Host city/is_active freshness
не обеспечивается одним panel_state lock; [18 §5/8](18-contexts-and-runtime-inputs.md#5-фазы-доступ-назначение-и-отзыв).
