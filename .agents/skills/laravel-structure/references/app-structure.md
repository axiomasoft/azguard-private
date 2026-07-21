# Канон структуры `app/`

Две оси организации:

- **Доменная ось** — бизнес-код. Корневая папка = слой, первая подпапка = домен (Document, Order, User…). Действует правило зеркала (см. `mirroring.md`).
- **Техническая ось** — инфраструктура, переиспользуемая всеми доменами. Подпапки называются по технологии/механизму (`Concerns/Enums`, `Support/Auth`), не по домену.

Класс попадает на техническую ось только если он не знает ни про один домен. Как только внутри появляется `Order`-специфика — переезд в доменную папку.

## Полное дерево (эталон)

```
app/
├── Actions/                # Д: use-cases — Actions/Order/Common/StoreAction.php
├── Attributes/             # Т+Д: PHP-атрибуты — Common/ + <Domain>/
├── Concerns/               # Т: трейты-примеси — Concerns/Enums/, Concerns/Media/
├── Console/Commands/       # Т: artisan-команды
├── Dto/                    # Д: Dto/<Domain>/{Form,View,Abilities,Mapper} + Dto/Actions/<Domain>/
├── Enums/                  # Д: Enums/Order/{OrderStatus,EventCode,Workflow/,Permissions/}
├── Events/                 # Д: Events/Order/{Created,StatusChanged}.php
├── Exceptions/             # Д: Exceptions/Order/OrderAccessException.php
├── Filament/               # Д: Resources/<Domain>/<Models>/{Pages,Schemas,Tables}
├── Health/                 # Т: health-чеки (Checks/)
├── Http/
│   ├── Controllers/        # Д: Controllers/Order/OrdersController.php
│   ├── Middleware/         # Т: сквозные middleware
│   ├── Requests/           # Д: Requests/Order/StoreOrderRequest.php
│   ├── Resources/          # Д: Resources/Order/OrderResource.php
│   └── Support/            # Т: helpers HTTP-слоя
├── Jobs/                   # Д: Jobs/User/SyncProfileJob.php
├── Listeners/              # Д: Listeners/Order/Notifications/...
├── MediaLibrary/           # Т: path generators, конверсии
├── Models/                 # Д: Models/Order/{Order,Item,History}.php
├── Notifications/          # Д: Notifications/Order/Notification.php
├── Observers/              # Д: Observers/Order/Observer.php
├── Policies/               # Д: Policies/Order/{Common,Review}Policy.php
├── Providers/              # Т: сервис-провайдеры (+ Filament/)
├── Repositories/           # Д: Repositories/Order/{OrderRead,OrderStore}Repository.php
├── Services/               # Д+Т: Services/Order/ И Services/{Broadcast,Log,Layout}/
├── Settings/               # Д: spatie settings по доменам
├── Support/                # Т: Support/{Auth,Enums}/ — статичные механизмы
├── TypeScript/             # Т: трансформеры для генерации типов
└── Utils/                  # Т: чистые хелперы Date/Str/Enum
```

`Д` — доменная ось, `Т` — техническая.

## Папки доменной оси

| Папка | Правило | Пример | Типичная ошибка |
|---|---|---|---|
| `Actions/<Domain>/<Subprocess>/` | Use-case = один Action; подпроцессы Common/Review/Application | `Actions/Order/Common/StoreAction.php` | Один God-Action на весь домен; Action в `app/Actions/` без домена |
| `Dto/<Domain>/` | Бакеты Form/View/Abilities/Repository; Mapper рядом с View | `Dto/Order/View/ListItemView.php`, `Dto/Order/Mapper/ViewMapper.php` | Свалить все DTO домена в одну папку без бакетов |
| `Dto/Actions/<Domain>/<Subprocess>/` | Зеркало `Actions/`; правило одного потребителя | `Dto/Actions/Order/Common/StoreCommand.php` | Положить Command в `Dto/Order/` — теряется связь с Action |
| `Enums/<Domain>/` | Статусы, коды событий; подпапки Workflow/, Permissions/, View/ | `Enums/Order/OrderStatus.php`, `Enums/Order/Permissions/CommonPermission.php` | `Enums/Models/Order/` — уровень потребителя запрещён |
| `Events/<Domain>/` | Имя — прошедшее время без префикса домена | `Events/Order/StatusChanged.php` | `OrderStatusChangedEvent` в плоской папке |
| `Exceptions/<Domain>/` | Доменные исключения по доменам (плоский `app/Exceptions/` — недостаток legacy-проектов) | `Exceptions/Order/OrderAccessException.php` | Копить все исключения в корне `Exceptions/` |
| `Http/Controllers/<Domain>/` | Тонкий контроллер: Request → Action/Repository → Response | `Http/Controllers/Order/OrdersController.php` | Бизнес-логика в контроллере |
| `Http/Requests/<Domain>/` | Form Request на каждую мутирующую операцию | `Http/Requests/Order/StoreOrderRequest.php` | Валидация в контроллере |
| `Http/Resources/<Domain>/` | API Resources | `Http/Resources/Order/OrderResource.php` | Ручная сборка массивов в контроллере |
| `Jobs/<Domain>/` | Очередные задачи домена | `Jobs/User/SyncProfileJob.php` | Job с логикой — Job только вызывает Action/Service |
| `Listeners/<Domain>/` | Слушатели доменных событий; рост — подпапки по назначению | `Listeners/Order/Notifications/SendStatusChanged.php` | Слушатель чужого домена в своей папке |
| `Models/<Domain>/` | Агрегат + связанные модели вместе | `Models/Order/{Order,Item,History}.php` | Модель-спутник (History) в корне `Models/` |
| `Notifications/<Domain>/` | Уведомления домена | `Notifications/Order/Notification.php` | — |
| `Observers/<Domain>/` | Один Observer на модель | `Observers/Order/Observer.php` | Логика в Observer вместо вызова Service |
| `Policies/<Domain>/` | Policy по подпроцессам, методы `can*` | `Policies/Order/CommonPolicy.php` | Один `OrderPolicy` на 30 методов |
| `Repositories/<Domain>/` | Read/Store раздельно; рост — подпапки (`Media/`) | `Repositories/Order/OrderReadRepository.php` | Запросы Eloquent размазаны по контроллерам |
| `Services/<Domain>/` | Доменная логика; рост — `Access/`, `Store/`, `<Subprocess>/` | `Services/Order/Access/OrderAccessEvaluator.php` | Универсальный `OrderService` на всё |
| `Settings/<Domain>/` | Классы настроек (spatie/laravel-settings) | `Settings/Order/WorkflowSettings.php` | Настройки в config/ вместо Settings |

## Папки технической оси

| Папка | Правило | Пример | Типичная ошибка |
|---|---|---|---|
| `Attributes/Common/` + `Attributes/<Domain>/` | Универсальные атрибуты в Common, доменные — по доменам | `Attributes/Common/Label.php`, `Attributes/Order/Stage.php` | Доменный атрибут в Common |
| `Concerns/<Tech>/` | Трейты-примеси метаданных и поведения, НЕ бизнес-логики; группировка по механизму | `Concerns/Enums/HasLabelAttribute.php`, `Concerns/Media/HasMediaPathAttribute.php` | Трейт с бизнес-логикой как способ «пошарить» код между доменами |
| `Support/<Tech>/` | Статичные механизмы без состояния и зависимостей (reflection-резолверы, регистраторы) | `Support/Enums/EnumCaseAttributeResolver.php`, `Support/Auth/PolicyAttributeRegistrar.php` | Инжектируемый сервис в Support (ему место в Services) |
| `Utils/` | Чистые функции-хелперы без Laravel-зависимостей | `Utils/DateHelper.php`, `Utils/StrHelper.php` | Хелпер, дёргающий БД или контейнер |
| `Services/<Tech>/` | Инфраструктурные сервисы: Broadcast, Log, Layout | `Services/Broadcast/ChannelManager.php`, `Services/Layout/SharedPropsService.php` | Доменная логика в техническом сервисе |
| `TypeScript/` | Трансформеры/коллекторы генерации типов для фронтенда | `TypeScript/EnumTransformer.php` | — |
| `MediaLibrary/` | Path generators, conversions для spatie/medialibrary | `MediaLibrary/Support/PathGenerator.php` | — |
| `Health/Checks/` | Кастомные health-чеки | `Health/Checks/QueueCheck.php` | — |
| `Http/Middleware/`, `Http/Support/` | Сквозные middleware и хелперы HTTP-слоя | `Http/Middleware/HandleInertiaRequests.php` | Доменный middleware (лучше Policy/Gate) |
| `Console/Commands/` | Artisan-команды; команда только вызывает Action/Service | `Console/Commands/PruneLogsCommand.php` | Логика внутри команды |
| `Providers/` | Сервис-провайдеры (+ `Providers/Filament/` для панелей) | `Providers/AppServiceProvider.php` | — |

## Псевдодомен Layout (UI-слой)

UI-DTO, которые описывают не бизнес-сущность, а оболочку страницы (shared props Inertia, меню, баннер impersonation), живут в выделенном псевдодомене **Layout** — это явно UI-слой, не бизнес-домен:

```
app/Dto/Layout/View/SharedPageProps.php
app/Dto/Layout/View/MenuItem.php
app/Services/Layout/SharedPropsService.php
```

Не растаскивать такие DTO по бизнес-доменам и не складывать в `Dto/Common/`.

## Filament-канон

`Filament/Resources/<Domain>/<Models>/` — домен, затем сущность во множественном числе, внутри — Resource-класс и папки Pages/Schemas/Tables (+ RelationManagers при необходимости):

```
app/Filament/Resources/
└── Order/
    └── Orders/
        ├── OrderResource.php
        ├── Pages/
        │   ├── ListOrders.php
        │   ├── CreateOrder.php
        │   └── EditOrder.php
        ├── Schemas/
        │   └── OrderForm.php
        ├── Tables/
        │   └── OrdersTable.php
        └── RelationManagers/
            ├── ItemsRelationManager.php
            ├── Schemas/
            └── Tables/
```

Правила:

- Resource — тонкий маршрутизатор: форма выносится в `Schemas/<Model>Form.php`, таблица — в `Tables/<Models>Table.php`.
- Несколько ресурсов одного домена — соседние папки: `Order/Orders/`, `Order/Items/`.
- `Filament/Pages/` — отдельные страницы панели (Dashboard и т.п.), вне доменной оси.

## Чего НЕ делаем

- Не держим пустые доменные папки «на вырост» — папка появляется вместе с первым классом.
- Не создаём уровень потребителя (`Enums/Models/...`, `Dto/Filament/...`) — кроме узаконенного `Dto/Actions/`.
- Не заводим параллельные оси (`app/Domain/Order/...` рядом с `app/Models/Order/...`) — одна таксономия.
- Не кладём доменный код в `Support/`, `Utils/`, `Concerns/` — техническая ось не знает о доменах.
