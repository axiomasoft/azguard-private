# 02 — Журнал решений (Decision Log)

Каждое решение — **утверждённая позиция для плана**, а не вариант. Где решение меняет продуктовую семантику,
это отмечено; владелец может отменить его в [15-owner-questions.md](15-owner-questions.md), иначе оно действует.

Уровни (как в Vaulter):

- **T0** — исправить до любого следующего релиза (обход изоляции, эскалация, ложное разрешение, потеря данных);
- **T1** — канон 1.0: breaking-изменения, которые делаются один раз в окне 0.4;
- **T2** — после 1.0, только аддитивно.

Ссылки `Nxx` — находки из [01-review.md](01-review.md), `Cxx` — находки Codex, `Pxx` — probes из [evidence](evidence/README.md).

---

<a id="d01"></a>
### D01 — Одно окно канона: 0.4.0, затем 0.9.0 RC и 1.0.0 · T1

**Решение.** Все переименования, новая схема, новый публичный API и слияние пакетов выходят одним релизом **0.4.0**
(«canon break»; текущая линия — 0.3.x). PHP-символы переименовываются жёстко, без алиасов-обёрток. Устаревшие
**ключи конфигурации** читает `ConfigNormalizer` с `E_USER_DEPRECATED` до 1.0 (как Vaulter D01). Данные
переносятся upgrade-миграцией с сохранением прав ([08 §5](08-data-model-and-migration.md#5-upgrade-03x--040)).
Затем **0.9.0** — freeze candidate (API заморожен, только исправления и аддитивные изменения), затем **1.0.0**,
когда зелёные все гейты [12 §5](12-operations-and-release.md#5-гейты-совместимости).

**Почему.** Двойная поверхность удваивает работу и тесты; у 0.x нет SemVer-обязательств. T0-исправления из этого
журнала **дополнительно** выходят патчем 0.3.x до канона (они нужны тем, кто уже использует 0.3).

**Отвергнуто.** Постепенные переименования по минорам; deprecated-обёртки для всех методов.

---

<a id="d02"></a>
### D02 — Архитектура: модульное ядро с чистым слоем значений; Eloquent — единственное хранилище 1.0 · T1

**Решение.** Вариант «B внутри одного дистрибутива» ([architecture-options](../architecture-options.md)):

```
Host / Gate / Blade / middleware / Filament
        │                       │
        ▼                       ▼
  Authorization (read)    Administration (write, с актором)
        │    ╲                 │
        │     ╲── Catalog ─────┤
        ▼                       ▼
  Kernel (чистые значения: ключи, ссылки, решение, алгебра, грамматика)  ← без Illuminate
        ▲                       ▲
  Sources / Constraints    Persistence\Eloquent (+ AzGuardDatabase, State)
```

- `Kernel\` — PHP без Illuminate (arch-тест): `PermissionKey`, `PermissionPattern`, `RealmId`, `RoleKey`,
  `SubjectRef`, `ContextRef`, `Decision`, `DecisionReason`, `Contribution`, алгебра объединения, грамматика.
- `Authorization\` — движок решения, кэш наборов, пакетная оценка, объяснение; читает через `PermissionSource`-ы.
- `Administration\` — все записи: `AccessManager`, операции, политика делегирования, запись событий.
- `Persistence\Eloquent\` — модели, `AzGuardDatabase` (соединение, транзакция, ревизия), встроенные источники.
- `Laravel\` — адаптеры: провайдер, фасад, Gate-мост, middleware, трейт, команды.

Хранилище назначений в 1.0 — только Eloquent (модели подменяемы, соединение настраивается). Внешние системы прав
подключаются как **дополнительный `PermissionSource`** (read-only, с объявленной волатильностью), а не как замена store.
Порт записи для альтернативного хранилища — T2 при реальном потребителе.

**Почему.** Чистый слой значений даёт детерминированные тесты алгебры и делает вход/выход движка явными — то, что
Codex ценил в B. Отдельный store-порт в 1.0 был бы обещанием без потребителя и без проверки (C11): «атомарность
записи + ревизии» и «authoritative reads» пришлось бы специфицировать для неизвестных хранилищ. Vaulter принял
тот же выбор (Laravel-native, D06 Vaulter).

**Отвергнуто.** C (policy engine / внешний Cedar/OpenFGA) — нет потребителя графов отношений; отдельный пакет
`azguard-kernel` — нет независимого потребителя, граница держится arch-тестом.

---

<a id="d03"></a>
### D03 — Пакеты: `azguard` (core + context) и `azguard-filament`; lockstep · T1

**Решение.**

1. `axioma-studio/azguard-context` **сливается** в core. Контекст становится измерением каждого назначения (D13, D15),
   а не надстройкой через `PermissionLayer`.
2. `axioma-studio/azguard-core` переименовывается в **`axioma-studio/azguard`** (главный пакет; параллель
   metapackage `axioma-studio/vaulter`); на Packagist старые имена помечаются `abandoned` → `axioma-studio/azguard`.
3. `axioma-studio/azguard-filament` остаётся отдельным (тяжёлая зависимость Filament) и требует
   `axioma-studio/azguard: self.version`.
4. Релизы lockstep: один тег монорепо → одинаковая версия всех split-пакетов (как Vaulter).

**Почему.** Core уже содержит контракты контекста (`ContextGuard`, `ContextGrantBuilder`, `PermissionContext`,
`hasPermissionIn`), а context импортирует пять `@internal`-типов core (`PermissionCache`, `PermissionStateRevision`,
`SubjectIdentity`, `RevisionedPermissionModelWrites`, `NullSafeUniqueIndex`) — это не расширение, а часть ядра в чужом
пакете. Слияние убирает класс проблем C09/«friend-модуль» и дублирование N10. Одна строка `composer require
axioma-studio/azguard` — одинаковый опыт с Vaulter.

**Отвергнуто.** Сохранить 3 пакета с SPI для context (аудит) — SPI пришлось бы делать под единственного потребителя,
которого мы сами же проектируем; новые пакеты `kernel/contracts` (аудит, «только при условиях» — условия не выполнены).

---

<a id="d04"></a>
### D04 — Канонический словарь · T1

Полная таблица и правила — [03-glossary-and-renames.md](03-glossary-and-renames.md). Ключевые выборы:

| Понятие | Термин | Почему не альтернатива |
|---|---|---|
| Пространство прав/ролей (было Panel) | **Realm** | «Panel» сталкивается с Filament panel в пакете, который сам поставляет Filament-plugin (та же причина, что Panel→Profile в Vaulter); «Namespace» занят PHP; «Domain» занят фичей генератора; «Area» слабее выражает изоляцию ролей |
| Объявленная возможность | **Permission** (`PermissionKey`) | «Ability» оставляется только для Laravel Gate |
| Шаблон в выдаче (`app.docs.*`) | **Permission pattern** | не путать с ключом каталога |
| Кто проверяется / получает права | **Subject** (`SubjectRef`) | совпадает со словом Vaulter для адресата grant'а |
| Кто меняет права | **Actor** (user или system с причиной) | совпадает с Vaulter D07 |
| Где действует назначение | **Context** (`ContextRef`: workspace, project, …) | уже термин продукта и моста Vaulter; «scope» остаётся только для Eloquent |
| Назначение роли | **Role assignment** | вместо `model_has_roles` + `model_has_scopes` |
| Прямая выдача права | **Grant** | вместо DirectGrant + ContextRole |
| Кто поставляет права при чтении | **Permission source** (было `GrantSource`) | «GrantSource» читалось бы как «источник Grant'ов» — а Grant теперь только прямая выдача |
| Обязательная проверка | **Constraint** | отдельно от источников (C06) |
| Итог | **Decision** (`Effect` + `DecisionReason`) | «AccessDecision» было событием |
| Обход проверок | **Superadmin** | отдельная политика, не значение `*` |

---

<a id="d05"></a>
### D05 — Ключ права всегда квалифицирован; «локальную» форму знают только enum и классы · T1

**Решение.**

- Строка на любом входе (`check`, Gate, CLI, конфиг, БД) — **только** квалифицированный ключ `realm.segment[.segment…]`,
  первый сегмент — зарегистрированный realm. Неквалифицированная строка → `UnqualifiedPermissionException`
  (без угадывания, без fallback на `'app'`).
- Enum и классы `Permission` объявляют realm сами: атрибут `#[Realm('app')]` на enum/классе **или** включение в
  `RealmBuilder::permissions([...])`. Enum, принадлежащий двум realm, запрещён (boot-ошибка).
- Realm проверки **выводится из ключа**. Понятие «текущая панель» для решения прав удаляется
  (`SetCurrentPanel`, `CurrentPanelState`, `default_panel`, `PanelResolver`).
- `Panel::scopedByPanelId(false)` удаляется: ключ без префикса realm не существует.

**Почему.** Устраняет N05 (мост Vaulter), N09 (четыре правила выбора панели), P01c (чужой ключ по набору панели по
умолчанию) и асимметрию строка/enum из аудита. Квалифицированный ключ уже содержит всю информацию — её нужно
использовать, а не дополнять ambient-состоянием.

**Отвергнуто.** Два типа строк `Ability::qualified()`/`Permission::local()` (аудит) — лишняя сущность; ambient
current realm как fallback — именно он создаёт расхождения.

---

<a id="d06"></a>
### D06 — Realm: грамматика, неизменяемый реестр, провайдеры · T1

**Решение.**

- `AzGuard\Realms\Realm` — `final readonly`; собирается `RealmBuilder` внутри `RealmProvider::realm(RealmBuilder): RealmBuilder`.
- Идентификатор: `^[a-z0-9][a-z0-9-]{0,63}$` — **та же грамматика, что ключ профиля Vaulter**; `*`, точка,
  пробелы, верхний регистр запрещены.
- `RealmRegistry::register()` при повторном id → `DuplicateRealmException`; `replace()` — явная замена до freeze;
  после `booted` приложения реестр заморожен (`RegistryFrozenException`).
- Поля realm: `id`, `label`, `permissions` (enum/классы/провайдеры каталога), `roles` (классы code-ролей),
  `contexts` (политика контекстов, D15), `constraints` (ключи constraint'ов, действующих в realm, D20).
  `path`, `namespace`, `basePath` удаляются из публичной модели (генератору они не нужны после D36).

---

<a id="d07"></a>
### D07 — Единый кодек идентичности: `SubjectRef`, `ContextRef`, `RoleKey` · T0 (утечка P07) / T1 (API)

**Решение.**

- `SubjectRef(type, id)` и `ContextRef(type, id)` — `Kernel\Identity`, readonly. `type` — morph alias
  (`Relation::getMorphAlias`), грамматика `^[A-Za-z0-9_.\\-]{1,128}$` (**без `:`**); `id` — строка: int →
  десятичная строка, строка — без преобразований (как `BlobScope` Vaulter D20), печатный ASCII без пробелов,
  ≤ 64 байт (под `varchar(64)` при `host_keys = string`). `7` и `'7'` — одна идентичность.
- `ContextRef::key()` = `"{type}:{id}"` — инъективно, потому что `type` не содержит `:`; то же значение хранится в
  колонке `context_key` и входит в ключ кэша. Глобальный контекст — `ContextRef::global()`, ключ `global`: в нём нет
  `:`, а в ключе любого другого контекста есть — коллизия исключена.
- `RoleKey(realm, key)` → строка `"{realm}:{key}"` (грамматика обоих частей без `:`).
- `IdentityCodec` — единственное место кодирования для БД, кэша, событий, логов; digest — sha256 от
  JSON-массива компонентов (как сейчас `SubjectIdentity::digestPayload`).
- Сравнение — только через `equals()` кодека; `AuthorizationContext::equals()` со строгим `===` удаляется.

**T0 в 0.3.x.** Патч: `ContextPermissionLayer::cacheDiscriminator()` возвращает `json_encode([$type, (string) $id])`;
`AuthorizationContext` отвергает `:` в `contextType`. Закрывает P07.

---

<a id="d08"></a>
### D08 — Типы ключей хоста: `azguard.ids.host_keys` как в Vaulter · T1

**Решение.** Ключ `azguard.ids.host_keys`: `string` (по умолчанию, `varchar(64)`) | `bigint` | `uuid` | `ulid` —
**те же имя и значения, что `vaulter.ids.host_keys`** (Vaulter D25). Применяется ко всем колонкам, хранящим
ключи хоста: `subject_id`, `context_id`, `granted_by_id`. `column_names.morph_type` удаляется (карта в
`ConfigNormalizer`: `int → bigint`). При `string` все привязки параметров идут через кодек как строки
(MySQL не теряет индекс на неявном приведении).

**Почему.** N16: один тип на все морфы не позволяет смешанных хостов; в экосистеме это третья независимая ручка
(`vaulter.ids.default`, `corex.ids.strategy`, `AZ_GUARD_MORPH_TYPE` — см. docblock `AzgardGuard` в Vaulter).

---

<a id="d09"></a>
### D09 — Публичный API: фасад-диспетчер, `Authorizer` для чтения, `AccessManager` для записи · T1

**Решение.** Нормативные сигнатуры — [05-php-api.md](05-php-api.md). Суть:

```php
AzGuard::check($user, 'app.documents.update', context: $workspace);          // bool
AzGuard::decide(AccessRequest::for($user, Documents::Update)->in($workspace)); // Decision
AzGuard::for($user)->in($workspace)->can(Documents::Update);                  // handle субъекта
AzGuard::for($user)->permissions('app');                                      // PermissionSet

AzGuard::access()->actingAs($admin)->assignRole($user, 'app:editor', context: $workspace);
AzGuard::access()->asSystem('import')->issueGrant($user, 'app.reports.export', expiresAt: $deadline);

AzGuard::realms()->get('app');  AzGuard::catalog()->all('app');
```

- `AzGuardManager`, `AzGuardManagerInterface`, `GrantBuilder`, `ContextGrantBuilder`(+factory) удаляются.
- `Authorizer` (контракт `@api`) — `decide()`, `allows()`, `decideMany()` (D27), `explain()` (D29).
- `AccessManager` (`@api`) — иммутабельный handle с актором: без `actingAs()`/`asSystem()` любая запись →
  `MissingActorException` (тот же принцип, что `Vaulter::drive()->actingAs()/asSystem()`, Vaulter D07).
- Фасад — только делегирующие методы, без собственного состояния; `AzGuard::fake()` остаётся (D39).

---

<a id="d10"></a>
### D10 — Трейт host-модели — только чтение · T1 (меняет DX)

**Решение.** `AzGuard\Concerns\HasAzGuard` (+ контракт `AzGuard\Contracts\AzGuardSubject`) даёт только:
`hasPermission(PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null): bool`,
`hasRole(RoleKey|string $role, ContextRef|Model|null $context = null): bool`, `permissions(string $realm, …): PermissionSet`,
`isSuperadmin(string $realm): bool`, `azguardRef(): SubjectRef`.

Удаляются из host-модели: `roles()`, `scopes()`, `directGrants()` (связи), `assignRole/removeRole/syncRoles`,
`grant/revoke`, `assignScopedRole/…`, `hasScopedRole`, `hasScopedPermission` (→ `hasPermission(..., context: $model)`),
`hasPermissionIn` (→ то же), `checkPermission`, `flushPermissions`, `hasContextGuard`, `getRoleNames`, `isSuperAdmin`
(→ `isSuperadmin`). Трейты `HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles` и контракт `AzGuardUser` удаляются.

**Почему.** Записи без актора обходят делегирование (N02) и дают разные события (N11); связи делают схему
публичным API. Для сидеров и тестов — `AzGuard::access()->asSystem('seed')` и `InteractsWithAzGuard` (D39).
**Продуктовая семантика меняется** (Spatie-подобное `$user->assignRole()` уходит) — [Q3](15-owner-questions.md).

---

<a id="d11"></a>
### D11 — Субъекты: любой Eloquent-модель, `SubjectResolver` вместо зашитой user-модели · T1

**Решение.**

- Субъект — любой объект, который `SubjectResolver` превращает в `SubjectRef` (по умолчанию — Eloquent-модель:
  morph alias + ключ; `Authenticatable` без модели — `getAuthIdentifier()` + класс). Контракт `@spi`.
- `azguard.subjects.types` — список разрешённых morph-типов субъектов (пусто = любой); используется валидацией
  записей и Filament-пикером.
- Поиск субъектов для UI/CLI — `SubjectDirectory` (`@spi`): `search(string $term, int $limit): list<SubjectOption>`,
  `find(SubjectRef): ?SubjectOption`; по умолчанию — провайдер guard'а `azguard.subjects.guard` (null = guard по
  умолчанию). Все упоминания `auth.providers.users.model` и ключа `'id'` удаляются (N15).

---

<a id="d12"></a>
### D12 — Граница API: namespace + манифест, теги только `@api`/`@spi`/`@internal` · T1

**Решение.**

| Где | Статус | Проверка |
|---|---|---|
| `Contracts\*` | `@api` (для вызова) или `@spi` (для реализации расширениями) — тег обязателен | arch-тест: каждый тип в `Contracts\` помечен ровно одним |
| `Kernel\*`, `Realms\Realm*`, `Events\*`, `Exceptions\*`, `Facades\AzGuard`, `Concerns\HasAzGuard`, `Testing\*` | `@api` | manifest |
| `Persistence\Eloquent\Models\*` | `@api` только для чтения и наследования (подмена модели) | doc + manifest |
| остальное (`Authorization\*`, `Administration\*` реализации, `Persistence\*`, `Laravel\*`, `Internal\*`) | internal по расположению | arch-тест: filament и хост-тесты не импортируют |

- `api-manifest.json` в каждом пакете генерируется из рефлексии (типы, методы, параметры **с значениями по
  умолчанию**, константы и значения, enum cases, `final/readonly/abstract`, реализуемые интерфейсы) и сравнивается
  в CI; любое изменение требует записи в `CHANGELOG` с классом изменения ([12 §5](12-operations-and-release.md#5-гейты-совместимости)).
- `@experimental` не вводится: нестабильные возможности — за флагом `azguard.features.*` и в `Internal\`.

---

<a id="d13"></a>
### D13 — Единая модель назначений: `RoleAssignment` и `Grant`, обе с контекстом · T1 (меняет схему)

**Решение.** Четыре таблицы назначений (`model_has_roles`, `model_has_scopes`, `az_direct_grants`,
`az_guard_context_roles`) заменяются двумя:

| Таблица | Строка означает | Идентичность (UNIQUE, все колонки NOT NULL) |
|---|---|---|
| `azg_role_assignments` | субъект S держит роль R в контексте C до T | `(role_id, subject_type, subject_id, context_key)` |
| `azg_grants` | субъект S имеет право/шаблон P в контексте C до T | `(subject_type, subject_id, permission, context_key)` |

Обе несут `expires_at`, `granted_by_type/_id`, `reason`, `created_at`. `context_key = 'global' | '{type}:{id}'` (D07)
плюс денормализованные `context_type`, `context_id` (nullable, для запросов и видимости). Колонки NULL в
идентичности нет → обычные unique-индексы на всех СУБД; `NullSafeUniqueIndex` (781 строка) и
`AssignmentDeduplicator` (319) удаляются после upgrade-миграции. Схема — [08](08-data-model-and-migration.md).

**Почему.** N10: два механизма одного понятия; N08/N16 — следствия. Роль в контексте проекта и право в контексте
workspace — одна операция «назначить X субъекту в C», одна проверка, один кэш, одна грамматика.

---

<a id="d14"></a>
### D14 — Роли: `RoleKey(realm, key)`, класс — сменяемая привязка · T1 (T0: DoS P02)

**Решение.**

- `azg_roles`: `realm`, `key` (`^[a-z0-9][a-z0-9-]{0,63}$`), `label`, `description`, `origin` (`code`|`database`),
  `definition` (FQCN, только для code), `is_superadmin`, `rank`. Идентичность — `UNIQUE(realm, key)`; `id` — только
  внутренний FK.
- Code-роль: класс, реализующий `AzGuard\Contracts\Roles\RoleDefinition` (`@spi`): `key(): string`, `label(): ?string`,
  `permissions(): list<UnitEnum|string>` (строки — квалифицированные ключи/шаблоны этого realm), необязательно
  `formerKeys(): list<string>` для переименований. Realm берётся из `RealmBuilder::roles([...])`.
- `azguard:roles:sync` сопоставляет по `(realm, key)`, затем по `formerKeys()` (переименование ключа сохраняет
  назначения), и **только затем** обновляет `definition` — перенос/переименование PHP-класса ничего не ломает (N12).
- Роль, чей `definition` не разрешается: движок **не бросает** на пути проверки; роль даёт пустой набор,
  событие `RoleDefinitionMissing` пишется один раз за запрос, `azguard:doctor` — error. (T0 в 0.3.x: `getRoleLogic()`
  → `null` + лог вместо исключения на пути чтения.)
- Code-роли **нельзя** изменять через UI/`AccessManager` (кроме `label`); DB-роли редактируются полностью.
- `level` → `rank` (int, по умолчанию 0) — используется только политикой делегирования (D23): актор не управляет
  ролями с `rank` выше своего максимального. «Priority when merging» удаляется (N20).
- Роль принадлежит ровно одному realm; её права вне этого realm отвергаются при sync/записи.

---

<a id="d15"></a>
### D15 — Контекст — измерение назначения; политика контекстов на realm · T1 (T0: P06)

**Решение.**

- Realm объявляет, какие типы контекстов принимает, и режим:
  ```php
  $realm->contexts(ContextPolicy::inherit('workspace', 'project'));   // глобальные ∪ контекстные (по умолчанию)
  $realm->contexts(ContextPolicy::isolated('workspace'));             // в контексте — только контекстные
  $realm->contexts(ContextPolicy::required('workspace'));             // без контекста — отказ
  $realm->contexts(ContextPolicy::none());                            // контексты не применяются (admin)
  ```
- Применимые назначения для запроса `(S, P, C)`:
  - `inherit`: `context_key ∈ {global, key(C)}`;
  - `isolated`: при `C` — только `key(C)`; без `C` — только `global`;
  - `required`: при `C` — `{global, key(C)}`; без `C` — `Decision::deny(ContextRequired)`;
  - `none`: только `global`; контекст запроса игнорируется, в `explain` — предупреждение.
- Контекст типа, не объявленного realm, → `UnsupportedContextException` при записи и `deny(ContextNotAccepted)` при
  проверке (не молчаливое игнорирование).
- **Членство** (tenant boundary) — отдельная обязательная проверка (D20, встроенный constraint
  `azguard/context-membership`), включаемая на realm: `ContextPolicy::inherit('workspace')->requireMembership()`.
  Без неё `inherit` означает «глобальная роль действует в любом контексте» — это документируется явно.

**Почему.** N07 (одна стратегия на все панели), C07/D16 Codex (контекст выбирает гранты ≠ проверяет членство).
**T0 в 0.3.x:** `merge_strategy` становится картой `panel => strategy` с глобальным fallback.

---

<a id="d16"></a>
### D16 — Жизненный цикл контекста: аргумент запроса, scoped ambient, `withinContext` · T0 (C02) / T1

**Решение.**

- Одноразовая проверка передаёт контекст **аргументом** (`AccessRequest::in()`, `hasPermission(..., context:)`);
  никакого `set()/restore` вокруг проверки (C02 исчезает как класс).
- Ambient-контекст запроса — `AzGuard\Context\CurrentContext` (scoped): ставит middleware `azguard.context`
  через `ContextResolver`-ы (`@spi`, `resolve(Request): ?ContextRef`); используется, только когда запрос не указал
  контекст явно и **realm принимает этот тип**.
- `AzGuard::withinContext(ContextRef $context, Closure $callback): mixed` — для кода без HTTP (jobs): сохраняет
  предыдущее, ставит новое **внутри** `try`, восстанавливает в `finally` до любых fallible-действий (F31).
- Jobs: контекст не переносится автоматически; `ShouldQueue`-job, которому нужен контекст, сериализует `ContextRef`
  и оборачивает `handle()` в `withinContext` (документированный рецепт + trait `InteractsWithAccessContext`).
- **T0 в 0.3.x:** в `ContextGuard::checkInContext()` перенести `set()` и `forgetRequestCache()` внутрь `try`.

---

<a id="d17"></a>
### D17 — Алгебра решения · T1 (меняет семантику)

**Решение.** Нормативно — [09-authorization-semantics.md](09-authorization-semantics.md). Порядок:

1. **Владение**: ключ принадлежит realm и есть в каталоге (точно или по динамическому определению) — иначе
   `Decision::notApplicable()` (Gate → `null`).
2. **Применимые контексты** по политике realm (D15).
3. **Superadmin** realm (D19) → allow, если ни один применимый constraint не помечен `bypassable: false`.
4. **Вклады**: каждый `PermissionSource` возвращает шаблоны с происхождением и сроком; объединение (∪). Нет
   совпадения → `deny(NotGranted)`.
5. **Constraints** в объявленном порядке: `fail` → `deny(ConstraintFailed, key)`; исключение → `deny(ConstraintError)`
   (fail-closed, с логом); `abstain` — не влияет.
6. `Decision::allow(Granted)` со списком вкладов (при trace) и `StateToken`.

- **Явных deny-правил в 1.0 нет** (запрет выражается constraint'ом). Причина: deny-override в RBAC-гранты ломает
  предсказуемость для админов; constraint с ключом объясним и тестируем. T2 — при реальном запросе (D21).
- Ошибка источника → исключение наружу (как сейчас: частичный набор не авторизует).

---

<a id="d18"></a>
### D18 — Грамматика ключей и шаблонов · T1

**Решение.**

- Сегмент ключа: `^[a-z0-9][a-z0-9_-]*$` (нижний регистр — нет зависимости от collation); динамический
  плейсхолдер `{name}` (`^[a-z][a-z0-9_]*$`) — только в **определениях** каталога; длина ключа ≤ 255.
- Шаблоны — только в **выдаче** (роль, грант): сегмент `*` — ровно один сегмент, `**` — последний сегмент, «всё
  глубже». Голый `*` без realm запрещён (superadmin — D19). `app.**` — «все права realm» (не superadmin: constraints
  действуют, `isSuperadmin` = false — это разные вещи, и это документируется).
- Легаси-грамматика (`features.wildcard_permission`, `WildcardPermissionMatcher`) удаляется в 0.4.0.
- Сменяемый `PermissionMatcher` удаляется: грамматика — часть контракта идентичности (кэш, БД, делегирование),
  её нельзя менять конфигом без смены данных. `Kernel\Grammar\PatternMatcher` — единственная реализация.

---

<a id="d19"></a>
### D19 — Superadmin — отдельная политика, а не значение ключа · T0 (N01, P01, P14) / T1

**Решение.**

- Источник superadmin — только роли с `is_superadmin = true`:
  - роль realm X → superadmin **только в X**;
  - встроенная роль платформы `*:superadmin` (realm `*` — единственное допустимое исключение из грамматики realm,
    создаётся только `azguard:superadmin:assign`/`AccessManager::assignPlatformSuperadmin()`, включается
    `azguard.superadmin.platform_role = true`) → superadmin во всех realm.
- Назначение superadmin-роли возможно **только** в глобальном контексте и только актором-superadmin того же уровня
  (или system).
- `azguard.superadmin.bypass_constraints` (по умолчанию `false`): superadmin обходит отсутствие грантов, но не
  constraints, помеченные `bypassable: false` (членство в tenant — по умолчанию не обходится).
- `*` в правах роли, гранте, источнике → ошибка валидации (запись) / отбрасывание с warning (внешний источник).
- `SuperadminPolicy` (`@spi`) заменяема: хост может вычислять superadmin иначе (например, по флагу модели).

**T0 в 0.3.x:** в `ClassRoleGrantSource` учитывать панель роли: `*` из роли панели X — только для X (роль
`super-admin` — как сейчас, глобально); `GrantBuilder`/`HasDirectGrants`/`guard:grant` отвергают `*` без
`--force-superadmin`; документация super-admin исправляется.

---

<a id="d20"></a>
### D20 — Constraints: упорядоченный реестр с ключами · T1

**Решение.** Контракт `AzGuard\Contracts\Authorization\Constraint` (`@spi`):

```php
interface Constraint
{
    public function key(): string;                                     // 'vendor/name'
    public function appliesTo(AccessRequest $request): bool;
    public function check(AccessRequest $request, EvaluationContext $context): ConstraintResult; // pass|fail|abstain
    public function bypassable(): bool;                                // может ли superadmin обойти
}
```

- Регистрация: `azguard.authorization.constraints` (FQCN, порядок = порядок массива) + `RealmBuilder::constraints([...])`
  (ключи; realm может только добавить). Дубликат ключа → boot-ошибка; `replace()` — явная.
- Порядок входит в `policy_fingerprint` (D25), меняющий ключ кэша решений explain/audit.
- Встроенные: `azguard/context-membership` (использует `ContextMembership` `@spi`: `isMember(SubjectRef, ContextRef): bool`),
  `azguard/resource-owner` — пример в документации, не в ядре.
- `PermissionLayer` (единственный binding) удаляется.

---

<a id="d21"></a>
### D21 — Сознательно отложено (T2, аддитивно)

Иерархия контекстов (`ContextHierarchy`: проект ⊂ workspace — наследование назначений); явные deny-правила;
relationship-граф/внешний Zanzibar-подобный backend; порт записи для альтернативного хранилища; раздельные ревизии
«роли/субъект» вместо глобальной (после бенчмарка, D24); durable outbox для событий AzGuard (сейчас достаточно
after-commit + опционального audit-журнала, D28); UI-«предпросмотр эффективных прав» в Filament.

---

<a id="d22"></a>
### D22 — Единственный путь записи: `AccessManager` на `AzGuardDatabase::mutate()` · T0 (Filament, Role::delete) / T1

**Решение.**

- Каждая операция `AccessManager` выполняется в `AzGuardDatabase::mutate(Closure)`: транзакция на соединении AzGuard
  → проверка делегирования (D23) → валидация (грамматика, каталог, realm роли, тип контекста) → запись строк →
  bump ревизии (если строки изменились) → запись событий (D28). Всё или ничего.
- Модели AzGuard остаются Eloquent-моделями (чтение, связи, подмена класса), но их `save/delete` вне `mutate()`
  логирует `UnsupportedDirectWriteException` в `local/testing` (исключение) и warning в production; bulk-запросы
  не перехватываются и официально unsupported (документ «Supported write paths»).
- CLI, Filament, сидеры, тестовый kit — клиенты `AccessManager`; `RolePermissionSynchronizer` становится внутренней
  операцией `setRolePermissions()` (сохраняет fingerprint-конфликт как `expectedFingerprint`).
- **T0 в 0.3.x:** `Role` получает `RevisionedPermissionModelWrites` (удаление роли каскадом снимает назначения
  без bump ревизии); `RolePermission` — тоже.

---

<a id="d23"></a>
### D23 — Политика делегирования: актор, мета-права, запрет эскалации · T0

**Решение.**

- `DelegationPolicy` (`@spi`, по умолчанию `DefaultDelegationPolicy`) вызывается каждой операцией `AccessManager`
  для актора-пользователя (system — пропускает делегирование, но не валидацию).
- Мета-права регистрируются в каждом realm автоматически (enum `AzGuard\Permissions\AccessPermission`):
  `{realm}.azguard.roles.view`, `…roles.manage`, `…assignments.manage`, `…grants.manage`, `…superadmin.manage`,
  `…doctor.view`.
- Правила по умолчанию:
  1. нужная мета-право в realm цели **в контексте цели или глобально**;
  2. **без эскалации** (`azguard.administration.prevent_escalation = true`): актор может назначить роль/выдать право,
     только если сам обладает всеми её правами в том же контексте; шаблон `**` — только superadmin realm;
  3. роли с `rank` выше максимального `rank` ролей актора в realm — недоступны;
  4. superadmin-роли — только superadmin того же уровня (D19);
  5. актор не может снять с себя последнюю роль, дающую `roles.manage` в realm (защита от самоблокировки,
     предупреждение, не ошибка, если есть другой superadmin).
- Отказ → `AccessManagementDeniedException extends AuthorizationException` (403) с кодом `delegation_denied`.
- **T0 в 0.3.x:** Filament RoleResource — поле `class_name` только для чтения; `DirectGrantResource`/`RoleResource`
  требуют мета-прав и проверяют «без эскалации»; документировать отсутствие делегирования в CLI.

---

<a id="d24"></a>
### D24 — Ревизия состояния и консистентность чтений · T0 (primary) / T1

**Решение.**

- `AzGuardDatabase` владеет строкой `azg_state(id=1, revision)`; bump — только внутри `mutate()` (Codex прав: наружу
  нет `advanceRevision()`). `StateToken` = `{revision, generation, policyFingerprint}` — публичное значение для
  внешних кэшей (Vaulter, D43).
- `azguard.database.reads = 'primary'` (по умолчанию): ревизия **и** все встроенные источники читают через write-PDO
  (`useWritePdo()`), закрывая обе гонки F32. `'default'` — осознанный выбор eventual consistency (doctor: warning).
- `azguard.cache.state_refresh = 'request'` (по умолчанию): ревизия читается один раз на request/job-lifecycle и
  обновляется после собственных мутаций процесса; `'check'` — на каждую проверку (строгий режим). Граница
  документируется: «отзыв действует для проверок, начавших request/job после commit отзыва» (при `check` — «после
  commit»). Закрывает P10 (N запросов на N проверок).
- Обход кэша — только если **текущий процесс** выполнил мутацию AzGuard в ещё не закоммиченной транзакции
  (read-your-writes), а не при любой транзакции соединения (P10b).
- Глобальная ревизия остаётся базовой топологией; раздельные ревизии (роли/субъект) — T2 после бенчмарка (D21).

---

<a id="d25"></a>
### D25 — Кэш наборов прав · T1

**Решение.**

- Кэшируется `PermissionSet` (шаблоны + ближайший `validUntil`) на ключ
  `digest(v3, subjectRef, realm, contextKeys, stateToken)`. Эпохи субъектов удаляются (ревизия в ключе делает их
  избыточными; ~120 строк и lock-логика уходят).
- `azguard.cache.store`: `null` (по умолчанию) — только request-кэш; имя store — межзапросный кэш.
  `expiration_time: null` на персистентном store — boot-ошибка (как сейчас).
- `generation` остаётся (смена при деплое); `policyFingerprint` = хэш определения realm, каталога, ролей из кода,
  constraints и их порядка — вычисляется при boot, делает кэш безопасным при смене кода без bump ревизии.
- Кэшированный набор не содержит решений constraints (они вычисляются на каждой проверке — дешёвые или сами кэшируют).

---

<a id="d26"></a>
### D26 — Gate-мост: authoritative для своих ключей · T1 (меняет семантику)

**Решение.**

- `GateBridge` (`Gate::before`): ability — не квалифицированный ключ зарегистрированного realm или нет в каталоге →
  `null`; своё → `true`/`false` по `Decision` (`azguard.gate.mode = 'authoritative'`, по умолчанию). `'additive'`
  (как в 0.3: `true`/`null`) — для постепенной миграции, deprecated к 1.0.
- Аргументы Gate: первый аргумент `ContextRef`/модель контекста → контекст запроса; иначе — ambient (D16). Прочие
  аргументы доступны constraints как `resource`.
- `azguard.gate.superadmin_scope`: `'owned'` (по умолчанию: superadmin отвечает `true` только на ключи AzGuard) |
  `'all'` (Laravel-style super-user: `true` на любую ability). Рецепт `Gate::before` в документации удаляется.
- `Gate::define('direct-grant')`, `PolicyAttributeRegistrar`, авто-`Gate::policy()` по ФС, генерируемые политики
  удаляются (N19): политика хоста — обычная Laravel-политика, которая вызывает `AzGuard::check()` для RBAC-части.
- Ability-ключи в `@can`, `can:` middleware, `$user->can()` — квалифицированные ключи или enum (`$user->can(Documents::Update)`).

---

<a id="d27"></a>
### D27 — Пакетная оценка с общим снимком · T1

**Решение.** `Authorizer::decideMany(iterable<AccessRequest>): DecisionSet` — все решения на одном `StateToken`,
одна загрузка назначений на `(subject, realm)`, контексты группируются. `DecisionSet::allowed(): list<int>`,
`get(int $i): Decision`. Нужен Vaulter для листинга (Vaulter D12 — `evaluateMany`) и Filament для таблиц.

---

<a id="d28"></a>
### D28 — События: after-commit, значения вместо моделей, `EventType` · T1

**Решение.**

- База `AzGuard\Events\AccessEvent` (abstract readonly): `eventId` (ULID), `occurredAt`, `actor` (`ActorRef`),
  `correlationId`, `stateRevision`, `type(): EventType` — **те же поля, что `Vaulter\Events\DomainEvent`**.
- Каталог: `RoleCreated`, `RoleUpdated`, `RoleDeleted`, `RolePermissionsChanged`, `RoleAssigned`, `RoleUnassigned`,
  `GrantIssued`, `GrantRevoked`, `AssignmentExpired` (при prune), `AuthorizationStateReset`; диагностические
  `RoleDefinitionMissing` и `AccessDecided` (только из `explain()`/`trace_decisions`). Payload — refs и скаляры, без Eloquent.
- `EventRecorder` вызывается внутри `mutate()`: при `azguard.features.audit = true` пишет строку в `azg_audit_log` в
  той же транзакции; Laravel-события диспатчатся **после commit** (`DB::afterCommit` на соединении AzGuard).
  Одно событие на фактическое изменение (no-op → нет события).
- `EventType`: `role.created`, `role.assigned`, `grant.issued`, … (`noun.verb_past`, как Vaulter).
- Слушатели кэш-инвалидации удаляются (инвалидацию делает ревизия).

---

<a id="d29"></a>
### D29 — Объяснение из той же оценки · T1

**Решение.** `Authorizer::explain(AccessRequest): Explanation` = решение + trace той же оценки (без повторного
опроса источников): применимые контексты, вклады с происхождением (`source`, `assignmentId`, `roleKey`, `pattern`,
`expiresAt`), результаты constraints, `StateToken`, итог Gate-моста (`true`/`false`/`null`) отдельно от локального
решения (Codex C05). `DecisionReason`: `Granted`, `Superadmin`, `NotGranted`, `NotApplicable`, `ContextRequired`,
`ContextNotAccepted`, `ConstraintFailed`, `ConstraintError`. Значения атрибутов субъекта в trace не пишутся
(приватность, R18).

---

<a id="d30"></a>
### D30 — Filament: клиент Administration API · T0 (class_name, делегирование) / T1

**Решение.** Детали — [11-filament.md](11-filament.md).

- Все записи — через `AzGuard::access()->actingAs(auth()->user())`; ресурсы не пишут модели напрямую.
- Роли: code-роли только для чтения (кроме `label`), `definition` не редактируется никогда; DB-роли — label,
  description, rank, права; `is_superadmin` — только superadmin.
- Назначения и гранты — единый ресурс «Access» с выбором субъекта (`SubjectDirectory`), realm, роли/права и контекста
  (`ContextDirectory` `@spi`); поиск, а не загрузка всех пользователей.
- Плагин: `AzGuardPlugin::make()->realm('admin')->manages(['app', 'admin'])` — состояние плагина живёт в экземпляре,
  глобальный конфиг не мутируется; ключи ресурсов — `{realm}.{resource-slug}.{ability}` из `Resource::getSlug()`.
- Страницы и виджеты при включённом enforce — fail-closed.

---

<a id="d31"></a>
### D31 — Видимость записей: явный API вместо глобального scope · T0 (P04) / T1

**Решение.**

- Глобальный scope `HasScopedRoles` удаляется. Вместо него — явный фильтр:
  ```php
  Project::query()->visibleTo($user, 'app.projects.view')->paginate();   // trait AzGuard\Concerns\ContextAware
  AzGuard::visibility()->constrain($query, $user, 'app.projects.view');   // для любого Builder
  ```
- Семантика: строка видна, если право выдано глобально (→ фильтр не добавляется), либо субъект — superadmin realm,
  либо есть назначение/грант в контексте `(morph(модели), id строки)`, чья роль/шаблон покрывает право (OR по всем
  назначениям — один `whereExists`). Нет субъекта → `whereRaw('1 = 0')` (fail-closed), без чтения `Auth`.
- Роли, покрывающие право, вычисляются по каталогу и `azg_role_permissions` (кэшируется по `StateToken`).
- **T0 в 0.3.x:** в `bootHasScopedRoles` — `on_missing_user` (по умолчанию `empty`), пустой набор назначений →
  `empty`, композиция через `orWhere`-группу; документировать, что это не граница безопасности при `all`.

---

<a id="d32"></a>
### D32 — HTTP и Blade: одна проверка · T1

**Решение.** Middleware: `azguard.can:{permission}[,{contextParam}]` (контекст из параметра маршрута, приведённого
через `ContextResolver`), `azguard.context` (ambient). Удаляются `azguard.panel`, `azguard.check` (+ атрибуты
`CheckPermission`/`SkipGuardCheck`), `azguard.grant`, `azguard.panel_check`, `azguard.roles`, alias `check.access`.
Blade: стандартный `@can` (Gate-мост authoritative); `@azcan`/`@azrole`/`@azdirect` удаляются. Для контроллеров —
`$this->authorize(Documents::Update)` или `AzGuard::authorize(...)` (бросает `AuthorizationException`).

---

<a id="d33"></a>
### D33 — Конфигурация: файл на пакет, типизированный объект, нормализатор · T1

**Решение.** Детали — [07-configuration.md](07-configuration.md). Файлы `config/azguard.php` и
`config/azguard-filament.php` (было `az-guard*.php`); readonly `AzGuard\Configuration\AzGuardConfig` читает через
`Illuminate\Contracts\Config\Repository` без кэширования; `config('azguard…')` вне `Configuration\` запрещён
arch-тестом; мёртвые ключи (N20) удаляются; ошибки безопасности — исключение при boot во всех окружениях.

---

<a id="d34"></a>
### D34 — База данных: соединение, префикс, фиксированные колонки · T1

**Решение.**

- `AzGuard\Database\AzGuardDatabase` (≙ `VaulterDatabase`, Vaulter D25): `connection()`, `mutate(Closure)`,
  `read(): Connection` (write-PDO при `reads = primary`), `driver()`. Все модели, миграции, источники, команды — через
  него; arch-тест запрещает фасад `DB` и `Schema::` без `->connection()` в `src`.
- `azguard.database.connection` (null = по умолчанию), `azguard.database.table_prefix = 'azg_'`; карта
  `table_names` удаляется. Колонки и индексы фиксированы; модели подменяемы (`azguard.models.*`), подмена обязана
  наследовать базовую модель и не менять соединение.

---

<a id="d35"></a>
### D35 — Миграции: пакет владеет схемой; fresh и upgrade — разные гарантии · T1

**Решение.** Обе части ядра загружают миграции (`loadMigrationsFrom`), публикация — опциональна и документирована
как «после публикации схема ваша». Схемо-влияющие ключи (`table_prefix`, `host_keys`, `connection`) фиксируются в
`azg_state.schema` (json) при установке; `azguard:doctor` сверяет их с конфигом и с реальными колонками. Уникальные
метки миграций. Upgrade 0.3 → 0.4 — одна переносящая миграция с preflight и dry-run командой ([08 §5](08-data-model-and-migration.md#5-upgrade-03x--040)).

---

<a id="d36"></a>
### D36 — Каталог: провайдеры, неизменяемость, без сканирования ФС · T1

**Решение.**

- Каталог realm собирается из провайдеров: `EnumCatalogProvider` (enum'ы realm), `ClassCatalogProvider` (классы
  `Permission`), `ConfigCatalogProvider`, пользовательские `CatalogProvider` (`@spi`), Filament-провайдер.
  Мета-права D23 добавляются автоматически.
- Коллизия одного ключа из разных провайдеров с разными метаданными → boot-ошибка (не молчаливая дедупликация).
- Каталог строится лениво один раз на процесс, замораживается вместе с реестром; `azguard:catalog:cache` пишет
  снимок в `bootstrap/cache/azguard.php` (аналог `config:cache`), без сканирования ФС в production.
- Обнаружение `*Policy.php`/`*Permission.php` по файловой системе, `#[GateAbility]`, `#[GuardPolicy]`, `#[RoleOnly]`
  удаляются (N17, N19); метаданные права — атрибут `#[Describe(label:, group:, description:)]` на enum case.

---

<a id="d37"></a>
### D37 — Исключения: иерархия и стабильные коды · T1

**Решение.** База `AzGuard\Exceptions\AzGuardException` (`code(): string` — `snake_case`, как в Vaulter D29).
Ветки: `ConfigurationException` (boot), `DefinitionException` (realm/каталог/роли), `InvalidIdentityException`
(грамматика), `AccessManagementException` (запись; `…DeniedException extends AuthorizationException`),
`AuthorizationEngineException` (ошибки источника/constraint). Отказ доступа — стандартный
`Illuminate\Auth\Access\AuthorizationException` (Gate). Таблица кодов — [05 §9](05-php-api.md#9-исключения).

---

<a id="d38"></a>
### D38 — Эксплуатация: команды `azguard:<area>:<verb>`, doctor, планировщик · T1

**Решение.** Детали — [12-operations-and-release.md](12-operations-and-release.md). Префикс `azguard:` (было `guard:`,
`make:guard-*`), генераторы `azguard:make:*`; `azguard:doctor` с расширяемыми проверками (`DoctorCheck`), `--json`,
кодом выхода ≠ 0 при ошибке; планировщик регистрирует `azguard:assignments:prune` при
`azguard.schedule.enabled = true`. Все пишущие команды — через `AccessManager::asSystem('cli: …')`.

---

<a id="d39"></a>
### D39 — Тестовый kit и контрактные наборы · T1

**Решение.** `AzGuard\Testing\InteractsWithAzGuard` (`actingAsWithPermissions()`, `givePermissions()`, `assignRole()` —
через `asSystem('test')`), `AzGuardFake` с однозначными ассертами (`assertRoleAssigned`, `assertGrantIssued`,
`assertGrantRevoked`, `assertChecked`, `assertDecided`); контрактные наборы для авторов расширений:
`PermissionSourceContractTests`, `ConstraintContractTests`, `SubjectResolverContractTests`, `ContextResolverContractTests`.
Production-код не импортирует `Testing\` (arch-тест). Тестовый режим кэша: probes показывают, что под
`RefreshDatabase` кэш не исполнялся (P10b) — после D24 он исполняется.

---

<a id="d40"></a>
### D40 — Установка · T1

**Решение.** `azguard:install`: публикует конфиг, спрашивает соединение/`host_keys`/платформенного superadmin,
показывает **pending-миграции AzGuard**, по умолчанию **не** запускает `migrate` (`--migrate` запускает с
предупреждением «Laravel выполнит все pending-миграции», в production требует `--force`), пропагирует код выхода,
в конце печатает `azguard:doctor`. «Star on GitHub» удаляется (или ведёт на верный репозиторий — [Q9](15-owner-questions.md)).

---

<a id="d41"></a>
### D41 — Совместимость и релиз · T1

**Решение.** Lockstep-теги; `api-manifest.json` (D12) + семантические снимки (конфиг-схема, события, команды и опции,
схема БД, грамматика, коды исключений) с 0.9.0; Roave BC Check — с 1.0.0 против последнего тега; consumer-фикстуры
на собранных архивах (core; core+filament) × prefer-lowest/stable × Laravel 11/12/13 × Filament 5 minors; отчёт
мутаций с denominator и списком исключений. Детали — [12 §4–§5](12-operations-and-release.md#4-релиз-и-артефакты).

---

<a id="d42"></a>
### D42 — Документация следует за кодом · T1

**Решение.** README/`docs/` переписываются после канона; каждый PHP-пример — исполняемый рецепт
(`tests/Recipes`); противоречия N01/N09 (super-admin per panel, `hasPermission` «current panel», `can:admin…`)
закрываются тестами-рецептами; внутренние коды задач (`C-11`, `P1.4 review`, `D27`) удаляются из docblock'ов `src`.

---

<a id="d43"></a>
### D43 — Экосистема: общие конвенции с Vaulter и контракт моста · T1

**Решение.** Детали — [10-ecosystem-vaulter.md](10-ecosystem-vaulter.md).

- Общий документ конвенций (ADR «Ecosystem conventions» в обоих репозиториях, одинаковый текст): Actor/ActorRef,
  `actingAs/asSystem`, `ids.host_keys`, `database.connection/table_prefix`, конфиг на пакет + typed config,
  событие с `eventId/occurredAt/actor/correlationId` и `EventType noun.verb_past`, коды исключений `snake_case`,
  команды `<pkg>:<area>:<verb>`, doctor, `Testing\` с контрактными наборами, грамматика ключей реестров.
- Общий код **не** выносится в отдельный пакет (связывание релизов без выигрыша) — T2 при третьем потребителе.
- Мост: Vaulter переименовывает `vaulter-azgard` → **`vaulter-azguard`** (`Vaulter\AzGuard\`), требует
  `axioma-studio/azguard:^0.4`, использует `Authorizer::decideMany()` с `ContextRef` из `OwnerRef` drive и
  квалифицированными ключами из карты, проверяемой при boot по каталогу AzGuard; кэширует с `StateToken`.

---

<a id="d44"></a>
### D44 — Бюджет производительности · T1

**Решение.** Нормативные бюджеты (проверяются тестами с `DB::listen`, [14](14-verification.md)):

| Сценарий | Бюджет запросов |
|---|---|
| Первая проверка `(subject, realm, context)` в request, холодный кэш | ≤ 3 (ревизия, назначения ролей, гранты) + 1 на DB-роли |
| Повторная проверка того же набора в request | 0 |
| Первая проверка при тёплом межзапросном кэше | 1 (ревизия; 0 при `state_refresh=request` после первой) |
| `Gate::before` для чужой ability | 0, O(1) по памяти (индекс realm-префиксов + хэш каталога) |
| `decideMany()` на N ресурсов одного субъекта и realm | ≤ 3 + число разных контекстов/100 (батчи `IN`) |
| Boot в production с `azguard:catalog:cache` | 0 обращений к ФС сверх include кэш-файла |

Плюс нагрузочный бенчмарк (R17): p95/p99 проверки, конкуренция записей на строке `azg_state`.
