---
name: filament
bucket: laravel-filament
version: 1.0.0
filament_version: "5.x"
description: "Filament v5 — Panels, Resources, Schemas, Tables, Widgets, Actions: file structure, make:filament-* CLI reference, correct namespaces, authorization, tenancy, common pitfalls. Scaffold only via artisan."
risk: write
persona: oss-dev
tags: [php, laravel, filament, ui, admin-panel, crud, livewire]
requires: [laravel, laravel-structure]
produces_for: []
outputs: ["app/Filament/**"]
snippets:
  - resource-stub.php
  - relation-manager-stub.php
  - forms-tables-reference.md
  - filament-development.md
adapters: [claude, cursor, fable]
sha256: ""
---

# Filament v5 — Skill

> **Версии:** актуальная — Filament **5.x** (вышел 2026-01: это v4 + Livewire 4, новых API нет; единый Schema API введён в **v4**). Код в стиле v3 (`form(Form $form)`, `Filament\Tables\Actions\*`) — устаревший, не генерировать.
> **Требования:** Laravel ≥ 11.28, PHP ≥ 8.2, Tailwind CSS ≥ 4.1, Livewire 4.
> **Ключевое правило:** весь scaffold — только через `php artisan make:filament-*`. Ручная генерация файлов запрещена: дорого по токенам и расходится со стандартными заглушками.
> **SSOT:** в проектах с Laravel Boost версионные гайдлайны поставляет сам Filament (`vendor/.../boost/guidelines/`) — они приоритетны; здесь — структура, CLI и проектные паттерны. Отслеживается через upstream.json.

## Контекст

CRUD-ресурсы, admin-панели, формы/таблицы/виджеты на Filament v5. UI описывается в PHP fluent-компонентами (`make()` + chainable-методы); большинство методов конфигурации принимают `Closure` для динамики.

## Алгоритм

1. Проверь версию: `composer show filament/filament` (ожидается `^5` или `^4`; v3 — мигрировать, не дописывать).
2. Сгенерируй файлы artisan-командой из справочника ниже (всегда `--no-interaction`, опции — через `--help`).
3. Заполни сгенерированные классы: схему формы — в `Schemas/<Model>Form.php`, таблицу — в `Tables/<Model>sTable.php` (v5-генератор выносит их из ресурса).
4. Создай Policy (`make:policy <Model>Policy --model=<Model>`) — Filament применяет её автоматически.
5. RelationManagers зарегистрируй в `getRelations()`; виджеты — в Panel Provider или `getHeaderWidgets()`.
6. Прогони чеклист качества.

## Структура файлов (v5-генератор)

```
app/Filament/Resources/Customers/
├── CustomerResource.php          # навигация, getPages(), getRelations()
├── Pages/                        # ListCustomers, CreateCustomer, EditCustomer, ViewCustomer
├── Schemas/
│   └── CustomerForm.php          # form schema (+ CustomerInfolist.php для view)
├── Tables/
│   └── CustomersTable.php        # колонки, фильтры, actions
└── RelationManagers/
```

Модульная архитектура (DDD): `modules/Blog/Filament/...` + `discoverResources()` в PanelServiceProvider с путём модуля.

## Корректные namespaces (источник частых ошибок)

| Что | Namespace |
|---|---|
| Поля форм (`TextInput`, `Select`, `Repeater`…) | `Filament\Forms\Components\` |
| Infolist-entries (`TextEntry`, `IconEntry`…) | `Filament\Infolists\Components\` |
| Layout (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`…) | `Filament\Schemas\Components\` |
| Утилиты схем (`Get`, `Set`) | `Filament\Schemas\Components\Utilities\` |
| Колонки таблиц (`TextColumn`, `IconColumn`…) | `Filament\Tables\Columns\` |
| Фильтры (`SelectFilter`, `Filter`…) | `Filament\Tables\Filters\` |
| **Все** actions (`EditAction`, `DeleteAction`, `BulkActionGroup`…) | `Filament\Actions\` — никогда `Filament\Tables\Actions\` или `Filament\Forms\Actions\` |
| Иконки | enum `Filament\Support\Icons\Heroicon` (например `Heroicon::PencilSquare`) |

Сигнатуры ресурса:

```php
use Filament\Schemas\Schema;

public static function form(Schema $schema): Schema      // НЕ form(Form $form)
public static function infolist(Schema $schema): Schema
public static function table(Table $table): Table
```

Типы переопределяемых свойств (union-типы обязательны):

```php
protected static string | BackedEnum | null $navigationIcon;   // не ?string
protected static string | UnitEnum | null $navigationGroup;    // не ?string
protected string $view;   // на Page/Widget — НЕ static
```

## CLI-справочник make:filament-*

```bash
# Установка панели
composer require filament/filament
php artisan filament:install --panels
php artisan make:filament-user

# Resources
php artisan make:filament-resource Customer                 # базовый
php artisan make:filament-resource Customer --generate      # form/table из схемы БД
php artisan make:filament-resource Customer --simple        # один экран, модалки
php artisan make:filament-resource Customer --soft-deletes
php artisan make:filament-resource Customer --view          # + view-страница
php artisan make:filament-resource Customer --model --migration --factory
php artisan make:filament-resource Customer --model-namespace=Modules\\Blog\\Models

# Relation Managers
php artisan make:filament-relation-manager CustomerResource orders number
php artisan make:filament-relation-manager CustomerResource orders number --soft-deletes
php artisan make:filament-relation-manager CustomerResource roles name --attach   # BelongsToMany

# Pages / Widgets
php artisan make:filament-page Settings
php artisan make:filament-widget StatsOverview --stats-overview
php artisan make:filament-widget RevenueChart --chart
php artisan make:filament-widget LatestOrders --table
php artisan make:filament-widget OrdersChart --resource=OrderResource --chart

# Export / Import
php artisan make:filament-exporter Customer
php artisan make:filament-importer Customer

# Production
php artisan filament:cache-components   # и filament:clear-cache для сброса
```

Полный список и актуальные флаги: `php artisan list make | grep filament` — флаги меняются между минорами, не полагаться на память.

## Реактивность форм

- Чтение соседнего поля: `->visible(fn (Get $get): bool => $get('type') === 'business')` на `->live()`-поле.
- Запись в соседнее поле: `->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')))`.
- Для текстовых инпутов — `->live(onBlur: true)`, чтобы не дёргать сервер на каждый ввод.
- BelongsTo: `Select::make('author_id')->relationship('author', 'name')` (`BelongsToSelect` не существует).
- Inline HasMany: `Repeater::make('items')->relationship()->schema([...])` — у Repeater `->schema()`, не `->fields()`.

Справочник полей/колонок/фильтров с примерами: snippets/forms-tables-reference.md.

## Авторизация (Laravel Policies)

| Policy-метод | Контролирует |
|---|---|
| `viewAny()` | видимость в навигации + list-страница |
| `create()` / `update()` / `view()` / `delete()` | соответствующие кнопки и страницы |
| `deleteAny()` | массовое удаление |
| `forceDelete()` / `restore()` | для soft deletes |

## Multi-tenancy

```php
// PanelServiceProvider
->tenant(Team::class, ownershipRelationship: 'team')
```

Запросы ресурсов автоматически скоупируются по тенанту через `getEloquentQuery()`.

## Типичные ошибки (из официальных гайдлайнов)

- Файлы (`FileUpload`) по умолчанию **private** — для публичного доступа явно `->visibility('public')`.
- `Grid`/`Section`/`Fieldset`/`Repeater` **не** занимают всю ширину по умолчанию — задавать `->columnSpan()`/`->columnSpanFull()`.
- `->dehydrated(false)` стирает значение из state до сохранения — только для UI-полей.
- Тесты edit-страниц: `->call('save')` (не `'create'`), без `assertRedirect()` — edit не редиректит.
- Тесты панели: всегда `$this->actingAs(...)` перед `livewire(...)`.

## Чеклист качества

- [ ] Все файлы созданы через `php artisan make:filament-*`
- [ ] Нет импортов `Filament\Tables\Actions\*`, `Filament\Forms\Actions\*`; `form()` принимает `Schema`
- [ ] `$navigationGroup` задан; `$recordTitleAttribute` задан для global search
- [ ] Policy создана, `viewAny()` покрывает нужные роли
- [ ] RelationManagers в `getRelations()`; для BelongsToMany — флаг `--attach`
- [ ] Labels/headings на языке проекта
- [ ] Production: `php artisan filament:cache-components`

## Ссылки

- snippets/resource-stub.php, snippets/relation-manager-stub.php
- snippets/forms-tables-reference.md — поля, колонки, фильтры, actions, widgets
- snippets/filament-development.md — проектные ожидания
- https://filamentphp.com/docs/5.x — официальная документация (resources, schemas, tables, actions, tenancy)
- Boost-гайдлайны Filament: `packages/panels/resources/boost/guidelines/core.blade.php` в filamentphp/filament (см. upstream.json)
