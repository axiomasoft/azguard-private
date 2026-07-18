# Обновление

## 0.3.0 — Смена wildcard-грамматики (breaking)

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

## Чистка публичного API перед 1.0 (breaking)

Чистка перед 1.0 приводит публичный API к единому набору голых
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

Ключи конфига и миграции не менялись. Существующий `config/az-guard.php` и
уже опубликованные миграции остаются валидными.

## Переход со Spatie Permission

Если вы переходите с `laravel-permission` от Spatie, см.
[страницу сравнения](/ru/introduction/comparison) — там есть таблица
соответствия возможностей и раздел с рецептами миграции.
