# 11 — Filament: админка поверх публичного API

Решения: [D23](02-decisions.md#d23), [D30](02-decisions.md#d30), [D46](02-decisions.md#d46), [D49](02-decisions.md#d49).
Находки: N02, N18, N19. Пакет `axioma-studio/azguard-filament` работает только через публичный API ядра: своей логики
прав и прямых записей в модели в нём нет.

## 1. Две разные «панели» — простыми словами

- **Панель Filament** — это экран админки: меню, ресурсы, страницы (`/admin`).
- **Панель AzGuard** — это пространство прав: кто в нём субъект, какие права, роли, настройки.

Часто они совпадают по смыслу (Filament-панель `/admin` ↔ панель AzGuard `admin`), но это не обязательно. Плагин
связывает их двумя настройками:

| Настройка | Что значит | Пример |
|---|---|---|
| `guardPanel('admin')` | права на ресурсы, страницы и виджеты **этой** Filament-панели живут в панели AzGuard `admin` | `admin.orders.view_any` |
| `manages(['admin', 'site'])` | правами каких панелей AzGuard можно управлять из этой админки | сотрудник назначает роли и сотрудникам, и покупателям |

```
Filament-панель /admin ──guardPanel──► AzGuard: admin   (кто может открыть «Заказы» в админке)
          │
          └────────manages──────────► AzGuard: admin, site (чьими ролями можно управлять отсюда)
```

## 2. Плагин

```php
// app/Providers/Filament/AdminPanelProvider.php (Filament)
->plugin(
    AzGuardPlugin::make()
        ->guardPanel('admin')
        ->manages(['admin', 'site'])            // null = все панели, которые разрешают управлять собой отсюда
        ->enforce()                             // ресурсы без права закрыты (fail-closed)
        ->source('database')                    // database | enum
        ->abilities([...])
        ->resources(roles: true, assignments: true, directGrants: true, panels: true, doctor: true)
        ->formExtensions(ApprovalFormExtension::class)   // поля от плагинов AzGuard (см. §6)
)
```

- Состояние — только в экземпляре плагина; `config()` не перезаписывается (N18). Значения по умолчанию — из
  `config/azguard-filament.php` ([07 §4](07-configuration.md#4-configazguard-filamentphp)).
- Права Filament-ресурсов попадают в каталог панели AzGuard обычным путём — плагином AzGuard `azguard/filament`,
  который Filament-плагин подключает к `guardPanel` через `AzGuard::configurePanel()`. Отдельного механизма нет.
- `checkPolicyExistence(false)` на классах ресурсов не вызывается (это статическое состояние, общее для всех
  Filament-панелей). `FilamentGate` отвечает на вопросы Gate по моделям ресурсов этой Filament-панели, поэтому
  политики не нужны (N19).
- id Filament-плагина — `azguard` (было `az-guard`).

### Кто может управлять панелью из админки

Filament-пользователь — субъект `guardPanel`. Управлять панелью из `manages` он может, если выполнено одно из двух:

1. это та же панель (или он её субъект) — действуют её мета-права `{panel}.azguard.…`;
2. панель объявила `administeredBy(guardPanel)` — действуют мета-права `admin.azguard.site.…` ([D23](02-decisions.md#d23)).

Иначе панель в списке `manages` не показывается, а doctor-проверка `filament.manages` пишет, почему.

## 3. Ключи прав ресурсов, страниц, виджетов

`{panel}.{resource}.{ability}`, где `panel` — `guardPanel`, `resource` — `Resource::getSlug()` (уникален в
Filament-панели), страница — slug страницы, виджет — `Str::kebab(class_basename)` **с проверкой уникальности**.
Коллизия → ошибка при boot с подсказкой задать `protected static ?string $azguardKey`. `class_basename` модели больше
не используется (N18). Метки прав — из `getModelLabel()`/`getNavigationLabel()`, группа — из навигационной группы.

## 4. Проверки доступа

| Что проверяется | Как | Если субъекта или плагина определить не удалось |
|---|---|---|
| Ресурсы (viewAny, view, create, update, delete, …) | `FilamentGate` через `Gate::before`: ability Filament + модель → ключ → решение панели `guardPanel` в текущем контексте | `false` при `enforce` |
| Страницы | `AuthorizesPage::canAccess()` → `AzGuard::panel(guardPanel)->check()` | `false` при `enforce` (сейчас `true`, N18) |
| Виджеты | `AuthorizesWidget::canView()` | `false` при `enforce` |
| Действия в ресурсах AzGuard | менеджер доступа управляемой панели (`actingAs(auth()->user())`) — проверка на сервере, а не `->visible()` | отказ → уведомление Filament |

Кнопки, которые пользователь не может выполнить, скрываются по тем же мета-правам. Это только удобство:
окончательную проверку делает пайплайн изменений на сервере.

## 5. Ресурсы

Все ресурсы работают с **выбранной панелью AzGuard** из `manages`. Выбор панели — фильтр в таблицах и первое поле в
формах. От выбранной панели зависят роли, типы контекстов, права каталога и **набор полей формы**: у разных панелей
разные модели и свои поля. Поэтому форма перестраивается при смене панели (`live()`).

### 5.1 `RoleResource`

| Поле/действие | Code-роль | DB-роль | Кто может |
|---|---|---|---|
| панель, `key` | только чтение | задаются при создании, потом только чтение | `roles.manage` |
| `label`, `description` | редактируется | редактируется | `roles.manage` |
| `definition` | только чтение (FQCN) | — | — |
| `rank` | редактируется | редактируется | `roles.manage`, не выше своего rank |
| `is_superadmin` | только чтение | редактируется | `superadmin.manage` |
| Права | только чтение (из кода) | `PermissionPicker` по каталогу панели; сохранение — `setRolePermissions()` с `expectedFingerprint` | `roles.manage` + без эскалации |
| Свои поля модели роли панели | по форме модели (§6) | по форме модели (§6) | `roles.manage` |
| Удаление | запрещено (удалить класс и `azguard:roles:sync --prune`) | `deleteRole()` | `roles.manage` |
| Держатели | список назначений; снятие — `revokeRole()` | то же | `assignments.manage` |

Поле `class_name` из формы удаляется полностью (N02). Создать code-роль из UI нельзя.

### 5.2 `RoleAssignmentResource` и `DirectGrantResource`

- Таблицы — модели **панели** (включая свои модели вроде `AdminRoleAssignment`) только на чтение. Фильтры: панель,
  роль или право, контекст, «истекает до», «кто выдал», свои поля (если модель их объявила). Подпись субъекта —
  `SubjectDirectory::describe()`, контекста — `ContextDirectory::describe()`.
- Создание: панель → субъект (`SubjectPicker`: поиск через `SubjectDirectory` панели, лимит 50, без загрузки всех
  пользователей) → роль (роли панели, доступные актору по rank) или право (`PermissionPicker` по каталогу; шаблон
  `x.**` виден только суперадмину) → контекст (`ContextPicker`: типы, которые принимает панель) → срок → свои поля.
- Сохранение — `AzGuard::panel($id)->manage()->actingAs(auth()->user())->assignRole(..., attributes: [...])` /
  `grantPermission(...)`. Свои поля передаются в `attributes`, а не пишутся в модель.
- Массовый отзыв — одна операция менеджера доступа (`revokePermissions()` или цикл внутри одной операции): одна
  транзакция, одна новая версия состояния, событие на каждую строку.
- Редактирование существующей строки — срок и свои поля (повторный `assignRole`/`grantPermission` с новыми
  значениями).

### 5.3 Результат «отправлено на подтверждение»

Пайплайн изменений может вернуть не только «применено», но и «отложено» (`ChangeResult::pending`, например плагин
подтверждений, [06 §3.1](06-extension-points.md#31-пример-подтверждение-вторым-администратором-4-eyes)). Filament
показывает это как отдельный результат: уведомление «Изменение отправлено на подтверждение» и ссылку на запрос.
Список ожидающих изменений и кнопки «подтвердить/отклонить» — ресурс **плагина подтверждений**, а не ядра Filament-пакета:
плагин регистрирует его через `FilamentFormExtension::resources()`.

### 5.4 `PanelsPage` и `DoctorPage`

- `PanelsPage` — то же, что `azguard:panels:list --settings --contributions`: панели, их итоговые настройки и откуда
  взято каждое значение, подключённые плагины и что они принесли. Только чтение. Доступ — `{panel}.azguard.doctor.view`.
- `DoctorPage` — `azguard:doctor --json` с группировкой по важности.

## 6. Свои поля в формах

Поля в формах берутся из трёх мест. Пакет ничего не знает о конкретных полях заранее.

| Откуда поле | Как попадает в форму | Пример |
|---|---|---|
| Своя модель панели | модель реализует `HasFilamentFields::azguardFilamentFields(): array` (схема Filament) | `department_id` у `AdminRoleAssignment` |
| Плагин AzGuard | `FilamentFormExtension`, зарегистрированное в Filament-плагине | «причина выдачи» от плагина подтверждений |
| `meta` без схемы | ключ-значение, только если модель разрешила (`$azguardMetaEditable = true`) | произвольные пометки |

```php
namespace AzGuard\Filament\Contracts;

interface FilamentFormExtension
{
    public function appliesTo(Panel $panel, FormTarget $target): bool;   // Role | RoleAssignment | DirectGrant
    /** @return list<Component> */ public function fields(Panel $panel, FormTarget $target): array;
    /** @return list<class-string<Resource>> */ public function resources(): array;   // свои ресурсы плагина
}
```

Правила полей:

- значения уходят в `attributes` операции менеджера доступа, запись — только через пайплайн изменений;
- серверная проверка — `azguardRules()` модели и шаги `ValidatesChange` плагинов; форма может показать те же правила
  как подсказку, но не заменяет их;
- поле, которого нет в модели и которое не разрешила ни одна проверка, отклоняется (`InvalidChangeAttributesException`).

## 7. Генерация (`azguard:filament:generate`)

Только `source = enum`: создаёт enum прав для ресурса с локальными ключами и `#[Describe]`. Enum подключается к
`guardPanel`. Генерация политик (`source = policy`) удаляется: Gate-мост делает их лишними (N19). Проверка
«устаревший enum» (enum есть, ресурса нет) — в doctor.

## 8. Тесты пакета (обязательные)

| Сценарий | № в [14](14-verification.md) |
|---|---|
| Редактор с `roles.manage` без права X не может добавить X в роль, выдать X или назначить роль с X | V20–V22 |
| `class_name`/`definition` не принимаются ни формой, ни Livewire-payload'ом | V23 |
| Две Filament-панели с разными `guardPanel` не влияют друг на друга (нет глобального конфига) | V24 |
| Страница с `AuthorizesPage` закрыта для пользователя без права и без `AzGuardSubject` | V25 |
| Поиск субъекта среди 10 000 пользователей — один запрос с `LIMIT` | V26 |
| Сотрудник `admin` назначает роль покупателю `site` при `administeredBy('admin')`; без него панель не показывается | V61 |
| Форма назначения показывает поля своей модели панели и плагина; неизвестное поле отклоняется | V62 |
| Результат `pending` показывается как «отправлено на подтверждение», изменение не применено | V63 |
