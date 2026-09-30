# Согласованные переименования — 2026-09-30

Область: целевая спецификация `opus/`, её API sketches, примеры, config, CLI/stub contracts,
workstreams и будущие verification scenarios. Исторический аудит 01 и старые runtime probes описывают 0.3;
их термины служат свидетельством старого кода, а не целевым API. Runtime пакета в этом задании не изменяется.

## Канонические имена

| Назначение | Целевой контракт | Где согласовано |
|---|---|---|
| Принимаемые модели субъекта | PanelBuilder::for([...], guard:, directory:) | D71, 05, примеры 00/07/16 |
| Дополнительные enum definitions и источники | Один PanelBuilder::permissions(array $definitions), D73 | 02/03/05/06/07, provider/plugin примеры, V77/V107 |
| Метаданные объекта действий | #[Resource(label:, model:)] на enum; PermissionSchema.resourceGroup | D71, 03/04/05/10/11 |
| Группы действий и политики | Permissions/<Group>, Policies/<Group>, Queries/<Group>, Abilities/<Group> | D56/D72, layout/API/config/Filament/CRM |
| Генератор группы прав | azguard:make:permission {Panel} {Group} [--model=] [--policy] [--abilities] | 03/12/13/14; stubs permission/policy/abilities |
| Селектор панели | HasAzGuard::guard(array\|string $guarded), SubjectPanels::guard(string); native Eloquent array branch сохранена | D74, 05/18, V108/R03 |
| Область назначения роли | Contexts/ProjectContext implements ContextDefinition; BaseRole::contexts() | 05/06/16; naming folder не меняет tenant ownership |

## Receivers и разные значения одного слова

```php
// Конструктор: enum + source object + зарегистрированное имя источника
$panel->for([User::class], guard: 'web')->permissions([
    ReportPermission::class,
    DatabaseSource::make()->dynamicPermissions(),
    'ldap',
]);

// Runtime: scoped manager динамических actions, а не список источников
AzGuard::panel('crm')->inTenant($organization)->permissions()->create('reports.export');

// Фабрика механизма источников
AzGuard::sources()->extend('ldap', $factory);
```

Panel::sources() — metadata getter определения панели; PanelSchema::subjects() — schema getter субъектов;
BaseRole::permissions() — права роли. Это отдельные классы/контракты, не старые aliases билдера.
Классы Source/SourceManager/FolderSource, config sources.*, папка Sources и настоящие Filament Resources
сохраняют свои значения. Resource metadata не вводит папку Resources; слово identity domain описывает
пространство идентичности и не является публичным классом Domain.

## Найденные остатки

При общей сверке исправлены оставшиеся Domain в дереве атрибутов, domain stub в layout,
domain в списке генераторов workstreams, прежние вызовы подключения источников, два назначения
permissions в билдере и API sketches ConfigSource с устаревшими SPI signatures.
Все действующие примеры билдера используют новый контракт. Карта «было → стало» может упоминать старое
имя в колонке прошлого; такие упоминания не являются целевыми вызовами.

## Проверка и границы

`python3 audits/2026-09-29-audit/opus/evidence/validate-dossier.py` проверяет локальные links/anchors,
нумерацию D01–D83 / V01–V120 (V40–V42 сняты) / C01–C22 / R01–R68, owning items, старые panel paths,
устаревшие fluent calls и наличие ровно одной permissions сигнатуры PanelBuilder.
`git diff --check` проверяет whitespace.

V107 — требование к будущим public manifest/generated consumer/runtime contract tests. Текстовая проверка
не доказывает runtime поддержку нового API и не проверяет фактическое выполнение factories/autodiscovery.
Математическая CRM модель не менялась; переименование не требует повторного запуска её тестов.


D80–D83 дополнительно: RoleCatalog read-only вместо writable RoleManager; BaseRole actual class вместо RoleView;
role definitions storage/profile registry удалены. Concrete context/plugin factories не наследуют generic make;
named typed DTO parameters. BeforeResult и PermissionAuthority/PolicyOnly/RequiresGrant — explicit dispatch,
CodeStateToken отделён от StateToken. Исторические research reports отмечены superseded и не служат runtime proof.
