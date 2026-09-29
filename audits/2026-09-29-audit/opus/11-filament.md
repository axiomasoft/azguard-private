# 11 — Filament: админ-UI поверх Administration API

Решение: [D30](02-decisions.md#d30). Находки: N02, N18, N19. Пакет `axioma-studio/azguard-filament` — клиент
публичного API ядра; доменной логики и прямых записей моделей в нём нет.

## 1. Плагин

```php
AzGuardPlugin::make()
    ->realm('admin')                         // realm, в котором регистрируются права Filament-ресурсов этой панели
    ->manages(['app', 'admin'])              // realm'ы, которыми управляет админ-UI (null = все)
    ->enforce()                              // FilamentGate authoritative для ресурсов этой панели
    ->source('database')                     // database | enum
    ->abilities([...])->keyTemplate('{realm}.{resource}.{ability}')
    ->resources(roles: true, assignments: true, grants: true, doctor: true);
```

- Состояние — **только в экземпляре плагина**; `config()` не перезаписывается (N18). Потребители (`FilamentGate`,
  провайдер каталога) получают опции через `Filament::getCurrentPanel()->getPlugin('azguard')`, а вне панели —
  через реестр плагинов по realm (не через глобальный конфиг).
- `checkPolicyExistence(false)` не вызывается на классах ресурсов (статическое состояние, общее для панелей):
  `FilamentGate` отвечает на Gate-вопросы по моделям управляемых ресурсов authoritative, поэтому политики не нужны.
- id плагина — `azguard` (было `az-guard`).

## 2. Ключи прав ресурсов, страниц, виджетов

`{realm}.{resource}.{ability}`, где `resource` — `Resource::getSlug()` (уникален в панели), страница — slug страницы,
виджет — `Str::kebab(class_basename)` **с проверкой уникальности** (коллизия → boot-ошибка с подсказкой задать
`protected static ?string $azguardKey`). `class_basename` модели больше не используется (N18).

`FilamentCatalogProvider` (`azguard/filament`) отдаёт определения с `label` из `getModelLabel()/getNavigationLabel()`
и `group` из навигационной группы.

## 3. Проверки доступа

| Поверхность | Механизм | Отказ, если не удаётся определить субъекта/плагин |
|---|---|---|
| Ресурсы (viewAny, view, create, update, delete, …) | `FilamentGate` через `Gate::before`: карта ability Filament → ключ; `AzGuard::decide()` в контексте из `ContextResolver` | `false` (fail-closed) при `enforce` |
| Страницы | `AuthorizesPage::canAccess()` → `AzGuard::check()` | `false` при `enforce` (было `true`, N18) |
| Виджеты | `AuthorizesWidget::canView()` | `false` при `enforce` |
| Действия внутри ресурсов AzGuard | `AccessManager` + `DelegationPolicy` (серверная проверка, не `->visible()`) | исключение 403 → уведомление Filament |

## 4. Ресурсы

### 4.1 `RoleResource`

| Поле/действие | Code-роль | DB-роль | Кто |
|---|---|---|---|
| `realm`, `key` | только чтение | задаются при создании, затем только чтение | `roles.manage` |
| `label`, `description` | редактируемо | редактируемо | `roles.manage` |
| `definition` | только чтение (показывается FQCN) | — (нет) | — |
| `rank` | редактируемо | редактируемо | `roles.manage`, не выше своего rank |
| `is_superadmin` | только чтение | редактируемо | superadmin realm |
| Права | только чтение (из кода) | `PermissionPicker` по каталогу realm, сохранение — `setRolePermissions()` с `expectedFingerprint` | `roles.manage` + без эскалации |
| Удаление | запрещено (удалить класс и `azguard:roles:sync --prune`) | `deleteRole()` (каскад назначений в транзакции, события) | `roles.manage` |
| Держатели | список назначений роли (read), снятие — `unassignRole()` | то же | `assignments.manage` |

Поле `class_name` из формы удаляется полностью (N02); создание code-ролей из UI невозможно.

### 4.2 `RoleAssignmentResource` и `GrantResource`

- Таблицы — Eloquent-модели `RoleAssignment`/`Grant` (read), с фильтрами realm, роль/шаблон, контекст, «истекает до»,
  «выдал»; подпись субъекта — `SubjectDirectory::describe()` (не сравнение FQCN с morph alias), контекста —
  `ContextDirectory::describe()`.
- Создание: `SubjectPicker` (поиск через `SubjectDirectory`, лимит 50; не загрузка всех пользователей), realm из
  `manages`, роль (роли realm, доступные актору по rank) или шаблон (`PermissionPicker`: ключи каталога; шаблон
  `x.**` виден только superadmin), `ContextPicker` (типы, принятые realm; поиск через `ContextDirectory`),
  `expiresAt`. Сохранение — `AzGuard::access()->actingAs(auth()->user())->assignRole()/issueGrant()`.
- Массовый отзыв — цикл `unassignRole()/revokeGrant()` **в одной** операции `AccessManager` (одна транзакция, одна
  ревизия, событие на строку).
- Редактирование существующей строки — только `expiresAt` (повторный `assignRole/issueGrant` с новым сроком).

### 4.3 `DoctorPage`

Показывает `azguard:doctor --json` с группировкой по severity; доступ — `admin.azguard.doctor.view` (мета-право).

## 5. Генерация (`azguard:filament:generate`)

Только `source = enum`: пишет enum прав на ресурс с `#[Realm]` и `#[Describe]`. Генерация политик (`source = policy`)
удаляется: authoritative Gate-мост делает их лишними (N19). Проверка «stale enum» (enum есть, ресурса нет) — в doctor.

## 6. Тесты пакета (обязательные)

- эскалация: редактор с `roles.manage` без права X не может добавить X в роль/выдать X/назначить роль с X (V20–V22);
- `class_name`/`definition` не принимается ни формой, ни Livewire-payload'ом (V23);
- две Filament-панели с разными realm не влияют друг на друга (плагин без глобального конфига) (V24);
- страница с `AuthorizesPage` недоступна пользователю без права и без метода `hasPermission` (fail-closed) (V25);
- выбор субъекта при 10 000 пользователях — ≤ 1 запрос на поиск с `LIMIT` (V26).
