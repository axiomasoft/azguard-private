# Выдержки решений досье для P3

Дословные выдержки из `audits/2026-09-29-audit/opus/02-decisions.md` (досье — нормативный источник; при расхождении
читать оригинал). Состав: D08, D13, D22, D24, D34, D35, D46, D63 — разделы досье фазы P3 (13 F3, 08).

## 8. D08 — Типы ключей хоста: ids.host_keys


**Кратко:** string подходит смешанным моделям; специализированный HK разрешён лишь без потери идентичности.

`string` (ASCII varchar64), bigint, uuid, ulid. Один HK хранилища применяется к subject_id, tenant_id,
context_id, actor_id; несовместимые модели требуют string/отдельного storage. System actor имеет alias
azguard.system, id=null и reason; не записывает строку system в bigint. Identity codec и DDL/collation
сверяются на реальных СУБД, не только через сравнение PHP строк. [08](08-data-model-and-migration.md).



## 13. D13 — БД хранит назначения; definitions ролей принадлежат коду

**Кратко:** role_grants/permission_grants — назначения; roles/role_permissions/role_contexts таблиц нет.
Роли — зарегистрированные PHP-классы с code-owned permissions/context filters. Enum права назначаются из кода,
relations или БД без копирования definitions. Opt-in permissions table хранит только дополнительные Grants actions.
Scope panel+tenant+context+origin, actors/expiry/conditions/state сохраняются (08). Изменение класса — deploy/build,
назначение — Change pipeline/version/events. Роль, отсутствующая в code catalogue, не даёт доступа; cleanup доступен.


## 22. D22 — Единственный путь записи с окончательной валидацией


**Кратко:** любое изменение сериализуется, проверяется и публикует committed state одинаково.

Все adapters -> ChangePipeline -> Storage::mutate. State lock **первый**, pipes и final validation
внутри transaction, effective rows + version + audit -> root commit -> events. Предварительная проверка не
заменяет финальную после изменения pipes. Прямые Eloquent save/delete/mass writes базовых моделей запрещены
во всех environments; raw SQL вне контракта и требует reset/doctor. Это уточняет прежний Q14 warning в production:
warning не мог обеспечить отзыв и теперь предложен strict invariant. Подробнее [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы), D63.



## 24. D24 — Версия панели и проверенное чтение authority


**Кратко:** счётчик не позволяет кэшировать смесь версий; guarantee относится к DB authority и указанной свежести.

StateToken = storageId/panel/incarnation/version/generation/fingerprint. Version меняется в transaction;
incarnation исключает cache resurrection после restore/reset. Primary/fresh state для reads; cold data читает
Grants mode: T_before -> scoped DB action catalogue/grants -> T_after, retry mismatch до 3. Повтор с validated warm grants
может не читать DB; live policies/restrictions всё равно работают. Refresh request/check и исключения старого
application snapshot — [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность). Внешний LDAP/membership
не получает strict revoke от одного DB token; adapter объявляет revision/freshness (D62/D64).



## 34. D34 — База данных и хранилища

**Кратко:** есть общее хранилище по умолчанию, где панели различаются колонкой `panel`. Панель может получить своё
хранилище: другое подключение к БД или свои таблицы. Хранилище — настройка `DatabaseSource`: у панели без него таблиц
нет.

**Решение.** Хранилище = подключение + префикс таблиц + тип ключей хоста + классы моделей (D46). По умолчанию —
`azguard.storages.default` (`connection: null`, `table_prefix: 'azg_'`) для всех панелей (Q19); другое —
`DatabaseSource::make()->storage('backoffice')`. Модели, `DatabaseSource` и команды работают через `Storage` панели; arch-тест запрещает фасад `DB` и статические запросы к моделям AzGuard вне
`Storage\`.


## 35. D35 — Миграции

**Кратко:** таблицы общего хранилища создаёт пакет. Для своего хранилища команда генерирует миграцию в проект, и туда
можно дописать свои колонки.

**Решение.** Ядро загружает миграции общего хранилища. `azguard:storage:migration {name}` пишет миграцию в
`database/migrations` хоста. Параметры, влияющие на схему, фиксируются в таблице состояния хранилища; doctor сверяет.


## 46. D46 — Хранилище, свои модели и свои поля — настройки `DatabaseSource`

**Кратко:** всё про базу данных — в одном классе-источнике `DatabaseSource`: где лежат таблицы, какие модели, какие
свои поля, нужны ли динамические права. Панель без этого источника таблиц не имеет.

**Решение.**

```php
DatabaseSource::make()
    ->storage('backoffice')                                    // именованное хранилище из конфига; по умолчанию 'default'
    ->models(roleGrant: AdminRoleGrant::class)                  // свои модели (наследники базовых)
    ->decisionFields(roleGrant: ['weekdays'])                   // поля, участвующие в решении
    ->dynamicPermissions()                                      // права можно создавать во время работы
    ->without(permissionGrants: true);                          // только роли, без выдачи отдельных прав
```

- **Хранилище:** `storage('default')` (по умолчанию), именованное из конфига или
  `Storage::own(prefix: 'admin_', connection: 'backoffice')`. Панели в одном хранилище различаются колонкой `panel`;
  у каждой — своя строка версии. Модели панели могут использовать атрибуты Laravel `#[Table]` и `#[Connection]` —
  хранилище сверяет их при загрузке.
- **Модели:** наследуют базовые модели AzGuard; идентификационные колонки и методы — `final`.
- **Свои поля:**
  - настоящие колонки — миграцией приложения (nullable в общем хранилище);
  - лёгкие поля — колонка `meta` (JSON, nullable, Q22) с типизированным кастом в модели;
  - запись — `$user->grantRole('editor', on: $project, fields: ['department_id' => 7])`;
  - описание — `azguardFields(): array` в модели (тип, подпись, правила): из него берутся проверка, схема панели и
    формы Filament. Неизвестное поле → ошибка валидации;
  - участие в решении — `decisionFields(...)`: поля загружаются вместе с выдачами и доступны ограничениям и хукам.
- Правильность `meta`: поле в `meta` нельзя индексировать и искать эффективно. Если по полю фильтруют или его
  проверяют ограничения на больших объёмах, это колонка. Doctor предупреждает, если поле из `decisionFields` лежит в
  `meta`.


## 63. D63 — Сериализованные изменения и scope административных операций

**Кратко:** state lock первый; final validation внутри retry-safe transaction.
Deletes/grants/sync/fingerprint checks сериализуются по panel_state до чтения dynamic definitions.
Pipes не меняют security identity и не выполняют внешние side effects. Eloquent прямые writes запрещены
во всех окружениях. Actor delegation — ответственность приложения; structural tenant integrity — ядра.
Root commit публикует state, nested rollback не публикует. UI record lookup/bulk/search всегда scope+origin.
[08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы).


