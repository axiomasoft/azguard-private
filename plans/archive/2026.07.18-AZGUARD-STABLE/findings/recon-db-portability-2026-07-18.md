# Recon: портируемость azguard между СУБД (2026-07-18)

> Слой 1. Отчёт Explore-субагента (read-only разведка репо azguard). Вердикт: repo-grounded.
> Вход Q2 (владелец: мультибаза, PG-приоритет, опциональный Redis — plan.md D23) и состава
> docker-матрицы P4.

## Общий вывод

Пакет написан **очень портируемо**. Схема — только скалярные колонки
(string/integer/timestamp/morphs); **нет** `json/jsonb`, `array`, нативного `uuid`,
generated/stored/virtual, `fullText`, GIN/partial/expression-индексов. Все запросы — равенства
по строковым ключам через query-builder, **без raw SQL и без ветвления по драйверу**. Матчинг
permission-ключей вынесен в **PHP (regex)**, не в SQL. Под Postgres (приоритет) всё работает
как есть. Содержательных PG-only фич, которые «не выйдут в MySQL», **нет**. Реальные риски —
два: (а) collation/регистрозависимость строковых равенств RBAC на MySQL; (б) locking для
epoch-инвалидации кэша при отсутствии Redis (решается database/file cache-драйвером).

## 1. Схема миграций — все нетривиальные колонки portable

- Полиморфные пары через `MorphColumns::add()` — ключ конфигурируется `int|ulid|uuid`
  (Support/Schema/MorphColumns.php:18-46); UUID/ULID — **строки** (`char(36)`), не нативный
  PG `uuid`. Portable.
- `role_permissions` — `string permission_key`, `string panel_id`, unique
  `(role_id, permission_key, panel_id)`, index `(panel_id, permission_key)`
  (core migration 000002:30-31).
- `direct_grants` — morph `grantable`, `string permission_key`, `string panel_id`,
  `timestamp expires_at nullable`, composite index с `expires_at` в хвосте (000002:40-49).
- `context_roles` — `string context_type/context_id` (специально string «to support UUID и int»),
  `string panel_id/permission_key`; unique из 6 колонок + 2 составных индекса (context 000010:28-44).
- `->change()` в 000004 (scope_class nullable) — down() ломается на ЛЮБОЙ СУБД при null-строках
  (docblock), **не PG-специфично**.

**Нет** JSON, array, native uuid, generated, fullText, GIN. Индексы — обычные B-tree.
Единственная тонкость MySQL — длина 6-колоночных unique под utf8mb4 (лимит префикса) — низкий риск.

## 2. Raw SQL / DB-specific — не найдено

Grep `whereRaw|selectRaw|orderByRaw|DB::raw|getDriverName|ILIKE|~|->>|#>|@>|whereJsonContains`
по `packages/*/src` — **пусто**. `DB::table()` только для JOIN (DatabaseRoleGrantSource.php:35-41,
обычные равенства). Ветвления по драйверу в рантайме нет.

## 3. Регистр/collation — ГЛАВНЫЙ практический риск MySQL

Матчинг ключей в PHP регистрозависим (`preg_match` без `i`: WildcardPermissionMatcher.php:23,
HierarchicalPermissionMatcher.php:28) — **после** загрузки из БД. Но SQL-**выборка** идёт
равенствами по строковым колонкам, где регистр решает collation:
- DatabaseRoleGrantSource.php:38-40 · DirectGrantSource.php:39-41 · ContextPermissionLayer.php:62-66 ·
  HasScopedRoles.php:151-154/216-220/268-275 · ContextRole.php:83/91 · ContextGrantBuilder.php:119/201-205.

Расхождение: **PG** — case-sensitive by default; **MySQL/MariaDB** дефолт `utf8mb4_*_ci` —
case-**INSENSITIVE**; **SQLite** (тесты) — BINARY, case-sensitive для ASCII. Значит на MySQL
`panel_id`='App' и 'app' схлопнутся; unique `(role_id, permission_key, panel_id)` станет CI и
может отвергнуть «разные» по регистру ключи как дубль; `model_type` (FQCN, регистрозависим в PHP)
сматчится нестрого. Риск смягчён каноникой на входе (`strtolower` panel в генераторах —
MakeGuardPanelCommand.php:38, MakeGuardPolicyCommand.php:45), но поведение по СУБД **разное**.

**Воркэраунд:** MySQL-миграция задаёт `utf8mb4_bin`/binary collation ключевым колонкам RBAC
(`panel_id`, `permission_key`, `model_type`/morph-типы, `scope_class`, `context_*`), ЛИБО
входная канонизация регистра ключей/панелей. PG/SQLite уже case-sensitive.

## 4. JSON-колонки — не используются вообще

Permissions/abilities/meta нигде не JSON — permissions построчно (`*.permission_key`, строка на
ключ). `whereJsonContains`/`->`-путей нет. Главное расхождение PG jsonb ↔ MySQL json **здесь
отсутствует by design**. Portable.

## 5. Locking / concurrency — Redis не обязателен

- Epoch-bump: PermissionCache.php:104-118 — атомарная `add/increment/put` эпохи оборачивается в
  `Cache::lock` **только если стор — LockProvider** (:112-115), иначе прямой `$bump()` без лока.
  Все стандартные Laravel-сторы (`array`,`file`,`database`,`redis`,`memcached`) — LockProvider,
  так что лок работает **и без Redis** (на database/file). Без Redis корректность сохраняется;
  ослабляется только атомарность на не-LockProvider кастом-сторе.
- `ScopedRoleCache` (Support/ScopedRoleCache.php) — чистый in-memory request-scoped, Redis не нужен.
- Дефолт cache-стора — `array` (Config.php:177): из коробки всё in-request, полностью Redis-free.
  Cross-request инвалидация через epoch требует **персистентного** стора (file/database/redis) —
  но не именно Redis.
- `lockForUpdate`/`sharedLock`/advisory locks (PG) — **не используются**. `DirectGrant::scopeActive`
  (DirectGrant.php:84-85) — `whereNull OR > now()`, portable.

## 6. Тест-конфигурация (текущая)

- `phpunit.xml:45-50` — `DB_CONNECTION=sqlite`, `:memory:`, `CACHE_DRIVER=array`.
  `tests/TestCase.php:29` — sqlite.
- CI `tests.yml:64-65,90-91` — матрица только PHP×Laravel×stability, **БД жёстко sqlite:memory;
  PG/MySQL матрицы НЕТ; docker-compose для тест-БД нет**.
- Epoch/LockProvider тестируется на `array`/кастом `spy_array` (PermissionCacheEpochInvalidationTest.php),
  **не на реальном Redis**.
- Само-скипов по драйверу нет. `ScopeClassMigrationRollbackTest` завязан на **текст** SQLite-
  исключения (:12-29) — хрупок при смене БД.

## 7. Реестр «фич под риском» → адрес в P4

| Фича | Якорь | Оценка | Обход / адрес |
|---|---|---|---|
| Вся схема миграций | core/context migrations | portable | — |
| Матчинг ключей (regex PHP) | Matching/*.php | portable | не в SQL — collation не влияет |
| Permissions построчно (не JSON) | *_grants/*_roles | portable | jsonb↔json расхождения нет by design |
| UUID/ULID morph | MorphColumns.php | portable | Laravel-строки |
| expires_at / active() | DirectGrant.php:84 | portable | — |
| ScopedRoleCache | ScopedRoleCache.php | portable (Redis-free) | in-memory |
| Epoch cross-request | PermissionCache.php:104-118 | portable, нужен персистентный стор | database/file cache без Redis; тест на database-драйвере — P4 |
| **Collation/регистр RBAC-ключей** | GrantSources/HasScopedRoles/Context* (§3) | **нужен воркэраунд на MySQL** | binary collation на ключевых колонках ИЛИ входная канонизация; **P4-item + тест на MySQL** |
| Длина 6-кол. unique | context :37, direct_grants :43 | низкий риск старый MySQL | `string(191)` под utf8mb4 при упоре в лимит |
| `->change()` down() | migration 000004 | не PG-only | документировать бэкфилл |
| Filament LIKE `%..%` | DirectGrantResource.php:64 | portable, регистр LIKE различается | косметика поиска, не RBAC |
| Тесты только sqlite:memory | tests.yml, phpunit.xml | «зелено на sqlite, иначе на PG/MySQL» | **PG+MySQL job в CI-матрицу + services — ядро P4** |
| ScopeClassMigrationRollbackTest на тексте SQLite | :22-29 | хрупок на др. БД | обобщить ассерт на `QueryException` без текста / скип по драйверу — P4 |

## Takeaway для матрицы P4

- **Матрица БД:** SQLite (:memory:, оставить — быстрый дефолт) + **PostgreSQL** (приоритет,
  реальная БД) + **MySQL 8** (first-class). MariaDB — опционально (collation-дефолты как MySQL;
  добавить, если дёшево). Redis-путь: тестировать epoch на **database cache-драйвере** (доказывает
  shared-hosting без Redis) И на redis (штатный прод-путь).
- **Collation-hardening** (RBAC-ключи binary/канонизация) — отдельный P4-item, доказывается
  MySQL-джобом; согласуется с «MySQL first-class» + fail-closed (D10). Это и есть «обыграть»,
  о котором спросил владелец.
- **Хрупкие тесты** (ScopeClassMigrationRollbackTest driver-text) — обобщить в P4.
