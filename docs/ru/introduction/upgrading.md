# Обновление

## 0.2 → 0.3

Этот релиз несёт полную волну ремедиации + fluent/DX-редизайна (см. `ARCHITECT_REVIEW.md`
пакета и план `2026.07.18-AZGUARD-STABLE`): fail-closed контракт query-scope, единую
immutable grant-грамматику, общую для `core` и `context`, fluent-плагин Filament + статические
конструкторы middleware, доменно-канонический неймспейс-реструктуринг и флип wildcard-грамматики.
Совместимых алиасов нет ни для чего из этого — обновляйте места вызова напрямую. Каждый раздел
ниже несёт `grep`-команду, чтобы найти, что нужно поменять.

### Query-scope: fail-closed по умолчанию (breaking)

Изоляция scoped-role-запросов раньше полностью отключалась внутри `runningInConsole()` —
включая джобы `queue:work`/`schedule:run`, выполняющиеся под аутентифицированным актором,
которые могли молча читать scoped-модели БЕЗ какого-либо фильтра. Этот bypass убран: изоляция
scope теперь активируется всегда, когда есть аутентифицированный пользователь
(`Auth::check()`), независимо от SAPI/рантайма.

Если в момент scoped-запроса нет активной панели (тот самый случай, который раньше маскировал
console-bypass), новый ключ конфига управляет исходом — **дефолт теперь самый строгий вариант**:

```php
// config/az-guard.php
'scope' => [
    // 'exception' (дефолт): бросает PanelNotSetException — scoped-запрос без панели это баг, падать громко
    // 'empty': `whereRaw('1=0')` — вернуть ничего, а не угадывать
    // 'all': поведение до 0.3 — применить все scope аддитивно (legacy, только явный opt-in)
    'on_missing_panel' => 'exception',
],
```

Если ваше приложение гоняет scoped-запросы вне HTTP-запроса без установленной панели (кастомные
команды, джобы, не проходящие через panel-aware точку входа), вы начнёте видеть
`PanelNotSetException`, если явно не установите панель или не вернётесь к `'all'`.

```bash
grep -rn "runningInConsole" packages/core/src/Concerns/HasScopedRoles.php  # должно быть пусто после обновления
```

### Единая fluent-грамматика прямых грантов (breaking)

`core` и `context` раньше учили двум разным грамматикам одной операции. Теперь есть единый
immutable fluent-корень, а ветка `context` — его расширение, а не отдельный конструктор:

```php
// Было
AzGuard::forUser($user)->on('app')->grant('posts.edit');
new ContextGrantBuilder($user, 'workspace', $workspaceId)->give('posts.edit'); // отдельная точка входа

// Стало — один корень, context — его ветка
AzGuard::forUser($user)->on('app')->until($expiresAt)->grant('posts.edit');
AzGuard::forUser($user)->inContext('workspace', $workspaceId)->until($expiresAt)->grant('posts.edit');
```

- `GrantBuilder::expiresAt()` переименован в `until()` (и в `core`, и в теперь TTL-парном
  `ContextGrantBuilder`).
- Оба builder'а — `final readonly`: каждый scope-сеттер возвращает новый инстанс; прямой
  `new ContextGrantBuilder(...)` — `@internal` (используйте `AzGuard::forUser()->inContext()`).
- Context-гранты теперь поддерживают срок действия (колонка `expires_at`, новая миграция — см.
  «Конфиг и миграции» ниже); истёкший context-грант больше не даёт доступ.
- Позиционные shorthands `AzGuardManager::grant()`/`revoke()`/`grants()` (и их формы
  `@method` на фасаде) по-прежнему работают, но стали `@internal` — задокументированный
  публичный путь — fluent-корень выше.

```bash
grep -rn "expiresAt(" packages tests --include='*.php'   # переименовать в until()
grep -rn "new ContextGrantBuilder(" packages tests --include='*.php'  # перейти на AzGuard::forUser()->inContext()
```

### Cut-line фасада (breaking)

Два мёртвых-извне резолвер-метода убраны из докблока `@method` фасада `AzGuard`:
`tryPermission()` и `panelIdForPermission()`. Они по-прежнему существуют на
`AzGuardManager`/`AzGuardManagerInterface` (реальные внутренние швы — их всё ещё зовут
`Permissions\PermissionName` и `Concerns\HasScopedRoles`), но теперь `@internal`: вызов через
фасад продолжает работать в рантайме, просто больше не является задокументированным,
замороженным контрактом. `AzGuard::isSuperAdmin()` переезжает в ту же `@internal`-секцию —
используйте `$user->isSuperAdmin()` (`HasPermissions`) вместо него.

```bash
grep -rn "AzGuard::\(tryPermission\|panelIdForPermission\|isSuperAdmin\)(" . --include='*.php'
```

### Filament: fluent-конфиг + статические конструкторы middleware (breaking)

`AzGuardPlugin` получает fluent-сеттеры вместо чисто конфиг-ориентированного подхода:

```php
// Было
// только config/az-guard-filament.php

// Стало — fluent, конфиг остаётся fallback'ом
AzGuardPlugin::make()
    ->enforce()
    ->source(RoleResource::class)
    ->abilities(['view', 'edit', 'delete'])
    ->keyTemplate('{panel}.{resource}.{action}')
    ->case(fn ($case) => Str::snake($case));
```

Все четыре параметризуемых middleware получают статический конструктор `::using()` рядом с
существующим alias-DSL (оба задокументированы; используйте тот, что читается лучше на месте
вызова):

```php
// Было (только alias-DSL)
Route::middleware('azguard.grant:posts.edit,app');

// Стало — статический конструктор, тот же эффект
Route::middleware(CheckDirectGrant::using('posts.edit', 'app'));
```

**Флип порядка аргументов:** алиас `PanelCheckAccess` меняется с `panel,permission` на
`permission,panel` — тот же порядок «что,где», что уже использовали остальные алиасы
(`CheckDirectGrant`, `CheckAccess`):

```php
// Было
Route::middleware('azguard.panel_check:app,posts.edit');
// Стало
Route::middleware('azguard.panel_check:posts.edit,app');
```

```bash
grep -rn "azguard\.panel_check:" . --include='*.php' --include='*.md'  # проверить порядок аргументов в каждом месте вызова
```

### Структурные переезды неймспейсов (breaking)

Catch-all-неймспейс `AzGuard\Support` (9 файлов без единой темы) распущен по доменным
неймспейсам; два корневых типа (`PanelProvider`, `PermissionKey`) тоже переезжают к своим
доменам:

| Было | Стало |
|---|---|
| `AzGuard\Support\Panel` | `AzGuard\Panels\Panel` |
| `AzGuard\Support\PanelResolver` | `AzGuard\Panels\PanelResolver` |
| `AzGuard\PanelProvider` | `AzGuard\Panels\PanelProvider` |
| `AzGuard\Support\PermissionName` | `AzGuard\Permissions\PermissionName` |
| `AzGuard\PermissionKey` | `AzGuard\Permissions\PermissionKey` |
| `AzGuard\Support\Config` | `AzGuard\Configuration\Config` |
| `AzGuard\Support\RequestState` | `AzGuard\Runtime\RequestState` |
| `AzGuard\Support\ScopedRoleCache` | `AzGuard\Runtime\ScopedRoleCache` |
| `AzGuard\Support\ResolvesGateAbilities` | `AzGuard\Abilities\ResolvesGateAbilities` |
| `AzGuard\Support\BladeHelper` | `AzGuard\Auth\BladeHelper` |
| `AzGuard\Support\Schema\MorphColumns` | `AzGuard\Database\Schema\MorphColumns` |

```bash
grep -rE 'AzGuard\\(Support|PanelProvider|PermissionKey)\b' . --include='*.php'
```

### Изменения сигнатур контрактов (breaking)

Два `@api`-контракта изменились в рамках закрытия структурных PHPStan-baseline-записей —
оба важны только если у вас есть собственный класс, их реализующий:

- `Authorizer::check()` теперь требует `Authorizable&Authenticatable` (было просто
  `Authorizable`) — обычный `Authorizable`, не-`Authenticatable` пользователь теперь бросает
  там `TypeError` вместо тихой работы.
- `Registry\Contracts\PermissionDefinition::label(): ?string` теперь часть контракта (метод
  вызывался Filament-ресурсами на конкретных классах, но никогда не был объявлен). Если у вас
  своя реализация `PermissionDefinition` — добавьте `label()`.

```bash
grep -rln "implements PermissionDefinition" packages tests --include='*.php'  # подтвердить, что у каждой реализации есть label()
```

### Wildcard-грамматика разрешений (breaking)

Дефолтная wildcard-грамматика теперь **иерархическая**
(`HierarchicalPermissionMatcher`): `*` соответствует ровно **одному** сегменту
между точками, `**` — рекурсивно. Wildcard-паттерны в ключах ролей/грантов
учитываются по умолчанию — старый гейт `features.wildcard_permission` больше
не отключает паттерны.

Что меняется:

- **Паттерны, на которые вы полагались при включённом флаге** (`'app.*'`
  покрывал `app.documents.view`), больше не пересекают точки. Перепишите их
  с учётом грамматики: `app.*` — один сегмент, `app.**` — всё поддерево.
- **Паттерны, которые раньше ничего не делали** (флаг выключен — старый
  дефолт), теперь выдают доступ с посегментной семантикой. Перед обновлением
  проверьте ключи ролей/грантов на `*`, если флаг был выключен.
- **Legacy-возврат (один deprecate-цикл):** установите
  `features.wildcard_permission = true`, чтобы вернуть грамматику 0.2
  (`*` пересекает точки). Старый смысл флага («учитывать ли wildcards вообще»)
  упразднён; теперь он только выбирает legacy-грамматику и будет удалён вместе
  с `WildcardPermissionMatcher` в следующем цикле.
- **`PermissionSet` вне контейнера** (standalone) теперь тоже по умолчанию
  использует иерархическую грамматику — в согласии с дефолтом приложения.
- **Усиление:** голый `*`, всплывший из кастомной `MergeStrategy` /
  `PermissionLayer`, теперь всегда отбрасывается catalog-фильтром — он не
  становится superadmin-грантом ни в одной из грамматик. Настоящие
  superadmin-wildcards из `GrantSource` не затронуты.

### Конфиг и миграции, изменённые в 0.2 → 0.3

Два ключа конфига получили новый смысл в этом цикле (оба уже описаны выше — здесь единое
место, где сверить свой опубликованный конфиг):

- `az-guard.scope.on_missing_panel` — новый ключ, см. «Query-scope» выше.
- `az-guard.features.wildcard_permission` — существующий ключ, **инвертированный смысл**,
  см. «Wildcard-грамматика разрешений» выше.

Две новые миграции в этом цикле (опубликуйте/прогоните их — ни одна уже применённая миграция
не редактируется на месте):

- `2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php` (core) —
  добавляет PK/unique-констрейнты на `model_has_roles`/`model_has_scopes`. Если у вас есть
  вручную вставленные дублирующиеся строки, миграция упадёт — дедуплицируйте перед обновлением.
- `2026_01_01_000011_add_expires_at_to_az_guard_context_roles_table.php` (context) —
  nullable-колонка `expires_at`, несущая новую TTL-парность context-грантов (см. «Единая
  fluent-грамматика прямых грантов» выше).

```bash
php artisan migrate --path=vendor/axioma-studio/azguard-core/database/migrations
php artisan migrate --path=vendor/axioma-studio/azguard-context/database/migrations  # если используете пакет context
```

## 0.1 → 0.2 — более ранняя чистка API (breaking, историческое)

> Этот раздел предшествует волне 0.2 → 0.3 выше — он документирует чистку, которая привела
> пакет к 0.2.0 (голый односложный публичный API, имена пакетов `axioma-studio/*`, Filament 5).
> Оставлен для тех, кто обновляется с чекаута до 0.2.

Эта чистка привела публичный API к единому набору голых
односложных имён. Совместимых алиасов нет — обновите места вызова
напрямую. Общепроектный search-and-replace покрывает почти всё.

### Трейт пользователя (`HasAzGuard`)

Префикс `Az` убран; трейт теперь просто выставляет голые методы из
`HasPermissions` и `HasRoles`.

| Было | Стало |
|---|---|
| `hasAzPermission()` | `hasPermission()` |
| `hasAzPermissionIn()` | `hasPermissionIn()` |
| `hasAzRole()` | `hasRole()` |
| `getAzPermissions()` | `permissions()` |
| `clearAzPermissionsCache()` | `flushPermissions()` |

### Прямые гранты — единый набор глаголов везде

| Было | Стало |
|---|---|
| `GrantBuilder::give()` | `grant()` |
| `GrantBuilder::list()` | `grants()` |
| `AzGuardManager::grantDirect()` | `grant()` |
| `AzGuardManager::revokeDirect()` | `revoke()` |
| `AzGuardManager::activeGrants()` | `grants()` |
| `HasDirectGrants::grantDirect()` | `grant()` |
| `HasDirectGrants::revokeGrant()` | `revoke()` |
| `HasDirectGrants::hasDirectGrant()` | `hasGrant()` |
| `HasDirectGrants::activeDirectGrants()` | `grants()` |

### Panel builder

| Было | Стало |
|---|---|
| `Panel::id()` (геттер) | `getId()` (`id()` теперь только сеттер) |
| `Panel::setNamespace()` | `namespace()` |
| `Panel::setBasePath()` | `basePath()` |
| `Panel::getPermissionName()` | используйте `resolvePermission()` |

### Переименованные / удалённые классы

| Было | Стало |
|---|---|
| `HasScopes`, `InteractsWithAzScopes` | `HasScopedRoles` |
| `GuardDoctor`, `DiagnosticsService` | `AzGuardDiagnostics` |
| `PermissionResolverCache` | `PermissionCache` |
| `Support\BaseRole` | `Roles\BaseRole` |
| `PermissionSet::toArray()` | `keys()` |
| `Context\Contracts\ContextMergeStrategy` | `Context\Contracts\MergeStrategy` (теперь `merge($global, $context)`) |
| `ResolvesContext::panel()` | `panelId()` |
| Filament `AzGuardResource` / `GuardResource` | удалены — см. руководство по Filament |

### Search and replace

```bash
grep -rE 'hasAz(Permission|Role)|getAzPermissions|clearAzPermissionsCache' . --include='*.php'
grep -rE '->give\(|grantDirect|revokeDirect|revokeGrant|hasDirectGrant|activeDirectGrants' . --include='*.php'
grep -rE 'GuardDoctor|InteractsWithAzScopes|PermissionResolverCache' . --include='*.php'
```

### Имя Composer-пакета

Core-пакет теперь публикуется как `axioma-studio/azguard-core` (старое имя
`azguard/azguard` упразднено):

```bash
composer remove azguard/azguard
composer require axioma-studio/azguard-core
```

### Filament

Пакет Filament теперь требует Filament 5 и заменяет старые базовые классы
`AzGuardResource` / `GuardResource` на конфиг-ориентированную модель без
шаблонного кода. См. [руководство по Filament](/ru/basic-usage/filament).

### Конфиг и миграции

Ключи конфига и миграции в этой более ранней чистке не менялись (что изменилось с тех пор —
см. «Конфиг и миграции, изменённые в 0.2 → 0.3» выше).

## Переход со Spatie Permission

Если вы переходите с `laravel-permission` от Spatie, см.
[страницу сравнения](/ru/introduction/comparison) — там есть таблица
соответствия возможностей и раздел с рецептами миграции.
