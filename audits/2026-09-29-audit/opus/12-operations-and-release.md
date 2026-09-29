# 12 — Эксплуатация, релиз, документация

Решения: [D38](02-decisions.md#d38)–[D42](02-decisions.md#d42), [D35](02-decisions.md#d35), [D44](02-decisions.md#d44).

## 1. Команды

Все пишущие команды — через `AzGuard::access()->asSystem('cli: <command>')`; `--json` у читающих; код выхода ≠ 0
при ошибке; `--force` обязателен для необратимых действий в production.

| Команда | Что делает |
|---|---|
| `azguard:install [--migrate] [--force]` | D40: конфиг, вопросы (соединение, `host_keys`, платформенный superadmin), список pending-миграций AzGuard, doctor |
| `azguard:doctor [--realm=] [--production] [--json]` | все `DoctorCheck` (§2) |
| `azguard:upgrade --dry-run \| --execute [--realm-map=] [--realm-for-orphans=] \| --drop-legacy` | 0.3 → 0.4 ([08 §5](08-data-model-and-migration.md#5-upgrade-03x--040)) |
| `azguard:catalog:list [--realm=] [--json]` | каталог с провайдерами и метаданными |
| `azguard:catalog:cache` / `azguard:catalog:clear` | снимок каталога и реестров в `bootstrap/cache/azguard.php` |
| `azguard:roles:sync [--realm=] [--dry-run] [--prune]` | code-роли ↔ `azg_roles` по `(realm, key)` + `formerKeys()`; `--prune` удаляет роли удалённых классов (с назначениями, через `AccessManager`) |
| `azguard:roles:list [--realm=]` | роли, origin, rank, число держателей |
| `azguard:roles:permissions {role} [--set=…] [--add=…] [--remove=…]` | права DB-роли |
| `azguard:roles:assign {subject} {role} [--context=type:id] [--expires=]` / `azguard:roles:unassign …` | назначения |
| `azguard:grants:issue {subject} {pattern} [--context=] [--expires=]` / `azguard:grants:revoke …` / `azguard:grants:list` | гранты |
| `azguard:assignments:list {subject} [--realm=]` | всё, что есть у субъекта, с контекстами и сроками |
| `azguard:assignments:prune [--before=]` | удаление истёкших (события `AssignmentExpired`) |
| `azguard:superadmin:assign {subject}` / `azguard:superadmin:unassign {subject}` | платформенный superadmin |
| `azguard:state:reset [--force]` | bump ревизии (после ручного вмешательства в БД) |
| `azguard:explain {subject} {permission} [--context=] [--json]` | `Explanation` ([09 §9](09-authorization-semantics.md#9-объяснение)) |
| `azguard:permissions:show {subject} {realm} [--context=]` | эффективный набор шаблонов |
| `azguard:make:realm`, `azguard:make:permissions`, `azguard:make:role`, `azguard:make:constraint`, `azguard:make:source` | генераторы (стабы в `stubs/`, публикуемые) |
| `azguard:filament:generate [--realm=] [--dry-run]` | enum прав Filament-ресурсов |

`{subject}` — `type:id` (`user:42`) или id при единственном разрешённом типе субъекта.

## 2. Doctor: проверки

| Ключ | Пакет | Что | Severity |
|---|---|---|---|
| `config.normalized` | core | устаревшие ключи, конфликты | warning / error |
| `schema.matches` | core | `azg_state.schema` = конфиг; реальные типы колонок = `host_keys` | error |
| `schema.migrated` | core | миграции 0.4 выполнены, legacy-таблицы (есть/удалены) | error / info |
| `realms.valid` | core | грамматика, провайдеры, пересечения enum | error |
| `catalog.collisions` | core | коллизии ключей между провайдерами | error |
| `catalog.cached` | core | `--production`: снимок каталога есть и свежий | warning |
| `roles.definitions` | core | `definition` разрешается, code-роли без `azg_role_permissions`, роли удалённых классов | error |
| `roles.sync` | core | code-роли, не синхронизированные с БД | warning |
| `grants.dead` | core | назначения/гранты с ключами вне каталога или с типом контекста, не принятым realm | warning |
| `superadmin.present` | core | есть хотя бы один superadmin или актор с `roles.manage` (иначе админ-UI мёртв) | warning |
| `consistency.reads` | core | `reads = default` при настроенных read-хостах | warning |
| `cache.store` | core | персистентный store + `ttl = null` (дублирует boot) | error |
| `gate.mode` | core | `additive` | warning |
| `direct_writes` | core | обнаружены прямые записи (счётчик из `GuardsDirectWrites`) | warning |
| `membership.configured` | core | realm требует членства — резолвер задан и реализует контракт | error |
| `filament.plugin` | filament | realm плагина существует, ключи ресурсов уникальны, нет stale enum | error |

## 3. Планировщик и about

`azguard.schedule.enabled = true` → `azguard:assignments:prune` по `schedule.prune_expired` (по умолчанию `daily`).
`php artisan about` — версия, realm'ы, `host_keys`, `reads`, cache store, ревизия.

## 4. Релиз и артефакты

| Тема | Правило |
|---|---|
| Версии | lockstep: один тег монорепо → split-репозитории `azguard`, `azguard-filament`; `branch-alias dev-main: 0.4.x-dev` → `1.0.x-dev` |
| Имена | `axioma-studio/azguard`, `axioma-studio/azguard-filament`; старые помечаются abandoned |
| Метаданные | `homepage`/`support` — реальный публичный репозиторий ([Q9](15-owner-questions.md)); в `composer.json` нет `version` |
| Consumer-фикстуры | чистый Laravel-проект, установка **собранных** архивов split-пакетов (без path-репозиториев): (a) `azguard`; (b) `azguard + azguard-filament`; (c) с `vaulter` + `vaulter-azguard` (E6) |
| Матрица | PHP 8.3/8.4/8.5 × Laravel 11/12/13 × prefer-lowest/stable; SQLite/PostgreSQL 13+/MySQL 8.0/MariaDB 10.6 для схемы и upgrade; Filament 5.x — каждая поддерживаемая minor-линия; Redis-store для кэша |
| Upgrade rehearsal | фикстура 0.3.x (реальная схема + данные всех видов из [08 §5](08-data-model-and-migration.md#5-upgrade-03x--040)) → `azguard:upgrade --execute` → матрица решений до/после |
| Отчёт о качестве | line/branch coverage, mutation score с явным denominator (covered-only), доля кода в mutation scope, список исключённых namespace с причиной (Codex C12) |

## 5. Гейты совместимости

С 0.9.0 каждый PR проходит, с 1.0.0 — блокирует:

| Гейт | Инструмент | Что фиксирует |
|---|---|---|
| PHP API | `api-manifest.json` (D12) + с 1.0 Roave BC Check против последнего тега | типы, методы, параметры **с значениями по умолчанию**, константы и значения, enum cases, `final/readonly`, интерфейсы |
| Конфиг-схема | снимок нормализованного конфига по умолчанию + карта нормализатора | ключи, типы, значения по умолчанию |
| События | снимок `EventType` + JSON-формы payload | имена, поля, типы |
| Команды | снимок сигнатур `azguard:*` (аргументы, опции, коды выхода) | CLI |
| Схема БД | снимок DDL после fresh-миграции на трёх СУБД | таблицы, колонки, индексы |
| Грамматика | property-тесты `PermissionGrammar`, `IdentityCodec` (инъективность, round-trip) | ключи, шаблоны, refs |
| Коды исключений | снимок `code()` всех исключений | `snake_case`-коды |
| Семантика | тесты-рецепты из документации + таблицы [09](09-authorization-semantics.md) | поведение решений |

Каждое изменение снимка требует записи в `CHANGELOG.md` с классом: API / behavior / schema / configuration /
serialization / event / security tightening / docs (как предлагал исходный аудит).

## 6. Документация

- README и `docs/` переписываются после канона: «Concepts» (realm, permission, role, context, subject, actor,
  superadmin), «Authorization semantics» (из [09](09-authorization-semantics.md)), «Supported write paths»,
  «Consistency guarantees» (дословная гарантия отзыва), «Upgrading from 0.3», «Vaulter integration».
- Каждый PHP-пример — исполняемый рецепт (`tests/Recipes/*`), CI проверяет, что сниппеты в `docs/` совпадают с рецептами.
- Удаляются: рецепт `Gate::before` для super-admin, «Option 3: direct wildcard grant», утверждение о per-panel
  wildcard, пример `can:admin…` без realm-семантики — вместо них рецепты по D19/D26.
- Русская версия `docs/ru` синхронизируется по тем же рецептам (один исходник примеров).

## 7. Тестовый kit для хоста

```php
use AzGuard\Testing\InteractsWithAzGuard;

it('lets editors update documents', function () {
    $fake = AzGuard::fake();
    $user = User::factory()->create();
    $this->actingAsWithPermissions($user, ['app.documents.update'], context: $workspace);   // через asSystem('test')
    $this->put(route('documents.update', $doc))->assertOk();
    $fake->assertChecked('app.documents.update');
});
```

`InteractsWithAzGuard` работает и под `RefreshDatabase` (кэш исполняется: обход только при незакоммиченной
мутации AzGuard в том же процессе — D24).
