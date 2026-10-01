# 11 — Filament: редакторы по схеме панели

Решения: [D23](02-decisions.md#d23), [D30](02-decisions.md#d30), [D46](02-decisions.md#d46), [D52](02-decisions.md#d52), [D54](02-decisions.md#d54).
Находки: N02, N18, N19. Пакет `axiomasoft/azguard-filament` работает только через публичный API ядра: своей логики
прав и прямых записей в модели в нём нет.

## 1. Две разные «панели» — простыми словами

- **Панель Filament** — экран админки: меню, ресурсы, страницы (`/admin`).
- **Панель AzGuard** — конструктор прав части приложения.

Filament-плагин связывает их двумя настройками:

| Настройка | Что значит | Пример |
|---|---|---|
| `guardPanel('admin')` | права на ресурсы, страницы и виджеты **этой** Filament-панели живут в панели AzGuard `admin`; она же — текущая панель в запросах Filament | `admin:orders.view_any` |
| `manages(['admin', 'seller'])` | роли и выдачи каких панелей AzGuard можно редактировать из этой админки | сотрудник выдаёт роли и сотрудникам, и продавцам |

```
Filament /admin ──guardPanel──► AzGuard admin   (кто может открыть «Заказы» в админке)
        │
        └────────manages──────► AzGuard admin, seller   (чьи роли и выдачи редактируются отсюда)
```

Кто может открыть редакторы ролей и выдач — решают **обычные права** ресурсов Filament в `guardPanel`, как для любой
другой страницы админки ([D23](02-decisions.md#d23)). AzGuard не знает, кто в приложении «администратор».

## 2. Плагин

```php
// app/Providers/Filament/AdmguardProvider.php (Filament)
->plugin(
    AzGuardPlugin::make()
        ->guardPanel('admin')
        ->manages(['admin', 'seller'])          // allowlist типов panel, не разрешение actor на все tenant/records
        ->enforce()                             // ресурсы без права закрыты
        ->definitions(FilamentDefinitions::Enums) // Enums (default) | Resources; source of definitions, не authority
        ->abilities([...])
        ->resources(roles: true, roleGrants: true, permissionGrants: true, permissions: true, panels: true, doctor: true)
        ->formExtensions(ReasonFieldExtension::class)   // поля от плагинов (§6)
)
```

- Состояние — только в экземпляре плагина; `config()` не перезаписывается (N18). Значения по умолчанию — из
  `config/azguard-filament.php` ([07 §4](07-configuration.md#4-configazguard-filamentphp)).
- В Enums definitions читает FolderSource из Permissions; Resources явно подключает FilamentSource
  (`ProvidesPermissions`) для build-time definitions из PHP Resource classes. Они не становятся DB catalogue rows.
  Assignments этих definitions может хранить DatabaseSource; dynamicPermissions для этого не требуется.
- `checkPolicyExistence(false)` на классах ресурсов не вызывается (это общее статическое состояние). `FilamentGate`
  отвечает на вопросы Gate по моделям ресурсов этой Filament-панели, поэтому отдельные политики не нужны (N19). Authority задан
  явно на definition: PolicyOnly sole policy, RequiresGrant assignment + optional policy veto (D83).
- id Filament-плагина — `azguard`.

## 3. Права ресурсов, страниц, виджетов

### 3.1 Definitions и authority задаются отдельно

```php
enum FilamentDefinitions { case Enums; case Resources; }
```

| Definitions | Где описаны права | Authority |
|---|---|---|
| Enums (default) | Permissions/<Group>/<PermissionEnum> с явными RequiresGrant/PolicyOnly | mode enum/case; assignments optional DB/code/relation |
| Resources (opt-in) | FilamentSource строит metadata из зарегистрированных PHP Resource/page/widget классов | explicit PermissionAuthority config на integration, по умолчанию Grants; Policy требует exact policy binding |

Ни вариант «в БД», ни наличие policy method не выбирают mode скрыто. DB здесь может хранить assignments;
дополнительные dynamic actions — отдельная opt-in функция core. Один resource/action = одна owned definition;
двойной owner из FilamentSource и enum — compile conflict, не auto merge. Resources не редактирует PHP behaviour
из UI. Для действий одной группы с разными modes используется Enums с явными case overrides.
Существующий source('database'/'enum'/'policy') API в целевой 1.0 исключён: definitions enum typed, authority отдельно.

### 3.2 Ключи

Локальное имя `{resource}.{ability}` в панели `guardPanel`, где `resource` — `Resource::getSlug()`; страница —
`pages.{slug}`, виджет — `widgets.{kebab(class_basename)}` **с проверкой уникальности** (коллизия → ошибка при
загрузке с подсказкой задать `protected static ?string $azguardKey`). `class_basename` модели не используется (N18).
Подписи прав — из `getModelLabel()`/`getNavigationLabel()`, группа — из навигационной группы. Всё это попадает в схему
панели, как права из enum.

## 4. Проверки доступа

| Что проверяется | Как | Если субъекта или плагина определить не удалось |
|---|---|---|
| Ресурсы (viewAny, view, create, update, delete, …) | `FilamentGate` через `Gate::before`: ability Filament + модель → имя права → решение панели `guardPanel` | `false` при `enforce` |
| Страницы | `AuthorizesPage::canAccess()` → `hasPermission()` в `guardPanel` | `false` при `enforce` (сейчас `true`, N18) |
| Виджеты | `AuthorizesWidget::canView()` | `false` при `enforce` |
| Вход в Filament-панель | `canAccessPanel()` → общий entry evaluator: qualified role этой панели/tenant AND optional `entry()` | `false` |

## 5. Редакторы по схеме панели

Все редакторы работают с **выбранной панелью AzGuard** из `manages`. Панель выбирается фильтром в таблицах и первым
полем в формах. Всё остальное форма берёт из `AzGuard::panel($id)->inTenant($tenant)->schema()` ([D54](02-decisions.md#d54)): группы
прав, какие права выдаются галочкой, какие роли редактируются, какие поля заполнять. При смене панели форма
перестраивается. Панели без `DatabaseSource` в `manages` не показываются (редактировать нечего), но видны на странице
«Панели».

### 5.1 `RoleResource`

| Объект | Что показывает UI | Что разрешено менять |
|---|---|---|
| BaseRole class | key/label/permissions/context filters/superAdmin из schema | ничего: code review/deploy |
| RoleGrant | subject/роль/tenant/project/origin/expiry/declared fields | назначить/обновить поля/отозвать через GrantManager |
| Enum permission | immutable key/authority/policy metadata | назначения только RequiresGrant |
| PolicyOnly action | badge «решает PHP policy», current capability | grant checkbox отсутствует; raw grant payload rejected |
| Opt-in dynamic action | tenant scoped key/label, mode Grants | создать/rename metadata/delete/назначить с actor validation |

RoleResource — read-only catalogue. Stale assignment form fingerprint включает code build и stored row revision;
состав роли не сохраняется как JSON/галочки из этой формы. SubjectGrants editor — отдельная ответственность.

### 5.2 `RoleGrantResource` и `PermissionGrantResource`

- Таблицы читают scoped `GrantManager::page/find` **panel+tenant+origin** (включая поля своих моделей вроде AdminRoleGrant); произвольный Eloquent builder не служит API редактора. Фильтры: панель,
  роль или право, сущность, «истекает до», «кто выдал», свои поля. Подпись субъекта — `SubjectDirectory::describe()`,
  сущности — `AssignmentScopeDirectory::describe()`.
- Создание: панель → субъект (поиск через директорию панели, лимит 50, без загрузки всех пользователей) → роль (те,
  что выдаются вручную) или право (статичное или динамическое) → сущность (типы, которые принимает панель) → срок →
  свои поля.
- Сохранение — `$subject->guard($id)->grantRole(..., on:, until:, fields:)` / `grantPermission(...)`. Свои
  поля передаются в `fields:`, а не пишутся в модель.
- Массовый отзыв — одна транзакция, одна новая версия, событие на каждую строку.
- Редактирование строки — срок и свои поля.
- Автоматические роли, выдачи из связей и своих источников и права из политик в этих таблицах не видны (их нет в БД). Чтобы понять, откуда у человека
  право, есть действие «Почему?» → `explain()`.

### 5.3 `PermissionResource` — динамические права

Показывается для панелей с `DatabaseSource::make()->dynamicPermissions()`. Список всех прав панели: статичные (из enum,
только чтение, с доменом и подсказкой о политике) и динамические (создание, подпись, группа, удаление вместе с
выдачами). Запись — `AzGuard::panel($id)->permissions()`.

### 5.4 `PanelsPage` и `DoctorPage`

- `PanelsPage` — то же, что `azguard:panels:list --settings --sources`: панели, их источники и плагины, итоговые настройки и
  откуда взято каждое значение, схема прав. Только чтение.
- `DoctorPage` — `azguard:doctor --json` с группировкой по важности.

Доступ к обеим — обычные права страниц Filament.

## 6. Свои поля в формах

Поля берутся из схемы панели ([05 §8](05-php-api.md#8-схема-панели)); Filament-пакет заранее о них ничего не знает.

| Откуда поле | Как попадает в схему | Пример |
|---|---|---|
| Своя модель панели | `azguardFields()` модели | `department_id` у `AdminRoleGrant` |
| Плагин AzGuard | плагин добавляет поле в панель (`$panel->fields(...)` в `register()`) | «причина выдачи» |
| Особая форма | `FilamentFormExtension` в Filament-плагине — когда нужен свой компонент Filament | выбор отдела деревом |

```php
namespace AzGuard\Filament\Contracts;

interface FilamentFormExtension
{
    public function appliesTo(PanelSchema $schema, FieldTarget $target): bool;     // Role | RoleGrant | PermissionGrant
    /** @return array<string, Component> компоненты по имени поля схемы */
    public function components(PanelSchema $schema, FieldTarget $target): array;
}
```

Правила полей: значения уходят в `fields:`, запись — только через пайплайн изменений; серверная проверка —
`azguardFields()` и pipes `changing`, форма показывает те же правила как подсказку; поле, которого нет в схеме,
отклоняется (`InvalidChangeFieldsException`).

## 7. Генерация (`azguard:filament:generate`)

Генератор создаёт enum в Permissions с #[Resource]/#[Describe] и explicit authority flag `--authority=grants|policy`
(default grants); `--with-policy` создаёт policy stub. Grants stub `return true` только pass; Policy stub
`return false` denies до явной реализации. Выбор policy stub не меняет mode. PolicyOnly bindings находятся
автопоиском; Grants `--with-policy` дополнительно генерирует explicit PolicyBinding в provider.
Компилятор проверяет declared method; policy stub не заменяет отсутствующий grant (N19 закрыт). Проверка «устаревший
домен» (домен есть, ресурса нет) — в doctor.

## 8. Тесты пакета (обязательные)

| Сценарий | № в [14](14-verification.md) |
|---|---|
| `class_name`/`definition` не принимаются ни формой, ни Livewire-payload'ом; статичную роль нельзя изменить из UI | V23 |
| Две Filament-панели с разными `guardPanel` не влияют друг на друга (нет глобального конфига) | V24 |
| Страница с `AuthorizesPage` закрыта для пользователя без права и без `AzGuardSubject` | V25 |
| Поиск субъекта среди 10 000 пользователей — один запрос с `LIMIT` | V26 |
| RoleResource показывает code roles read-only; grant editor разрешает только assignments RequiresGrant; policy mode badge/raw reject | V61 |
| Enums/Resources definition modes отделены от authority; Grants с DB assignment без dynamic flag; PolicyOnly без store; generated stubs fail closed | V75 |
| Динамическое право создаётся в `PermissionResource`, выдаётся и проверяется; удаление забирает выдачи | V76 |
| Форма выдачи показывает поля своей модели и плагина; при смене панели набор меняется; неизвестное поле отклоняется | V62 |
| Панель без `DatabaseSource` не показывается в редакторах, но видна на странице «Панели» | V63 |


## 9. Тенанты, project bindings и все UI входы

Filament tenant selection устанавливает scoped default текущей панели после аутентификации; client payload
не может подменить target tenant. `manages` задаёт список панелей, а серверная policy приложения отдельно
проверяет actor на выбранные target panel/tenant/subject/role/context/fields. Право открывать ресурс само по себе
не разрешает global grants, superadmin flag, wildcard namespace или другой tenant.
Каждый Livewire create/edit/delete/bulk/attach action повторяет проверку. При отсутствии actor/panel/tenant — отказ.

Role catalogue показывает allowed AssignmentScopeDefinition classes/filters и required; assignment пишет stable role/context aliases.
Grant form: target panel -> tenant -> subject -> role -> допустимый context type -> project текущего tenant -> срок/fields.
Directories получают actor и AccessScope. Смена tenant очищает сохранённые project/role/fields/fingerprint.
Find record id проверяет полный scope+origin; чужой id не отдаётся форме и не отзывается bulk API.
Definitions BaseRole не копируются в БД, UI читает их через PanelSchema.

Resource list/global search/relations/widgets/counts/export фильтруются exact visibility по View до count/page.
ViewAny только открывает страницу; Update/Delete/Restore/ForceDelete проверяются на actual/prospective resource;
bulk action не использует один Allow для всех строк. Jobs экспорта передают scope, при исполнении повторно authorize.
Два resources одной модели имеют разные resource permission keys; FilamentGate не угадывает slug из класса модели.
Enforce policy распространяется на все эти surfaces; отсутствие exact query adapter — явная ошибка настройки.
Проверки V94–V95; полный пример — [16](16-crm-and-workflows.md).

Редакторы назначений контекстов/declared grant fields используют LookupContext с actor **и target subject/BaseRole/proposed fields**.
Например admin из Самары выбирает проект для target seller из Казани: фильтр seller использует target city,
а право открыть/сохранить редактор — admin delegation. RoleResource показывает allowed profile schemas/config;
UI не сохраняет PHP/SQL и не меняет owner resolver. Для inspection/revoke отображаются authorised inactive/expired
records, иначе нельзя исправить доступ после деактивации. Тесты настоящих UI flows — R36–R40 в [17](17-crm-acceptance-tests.md).
