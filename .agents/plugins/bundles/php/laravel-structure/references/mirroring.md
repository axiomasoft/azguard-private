# Правило зеркала

**Одна таксономия доменов во всех слоях.** Если домен называется `Order` в `Models/`, он называется `Order` в `Enums/`, `Policies/`, `Repositories/`, `database/factories/`, `tests/Feature/` — везде. Подпроцессы (`Common/`, `Review/`, `Application/`) — одинаковые подпапки в `Actions/`, `Dto/Actions/`, `Enums/<Domain>/Permissions/`, `Policies/<Domain>/`.

Зеркало — это навигация: зная один файл сущности, разработчик (и агент) вычисляет пути всех остальных без поиска.

## Одна сущность Order — все её файлы по слоям

| Слой | Путь |
|---|---|
| Модель | `app/Models/Order/Order.php` |
| Фабрика | `database/factories/Order/OrderFactory.php` |
| Сидер | `database/seeders/OrderSeeder.php` |
| Миграция | `database/migrations/2026_01_01_000000_create_order_orders_table.php` |
| Enum (статус) | `app/Enums/Order/OrderStatus.php` |
| Enum (права) | `app/Enums/Order/Permissions/CommonPermission.php` |
| Контроллер | `app/Http/Controllers/Order/OrdersController.php` |
| Form Request | `app/Http/Requests/Order/StoreOrderRequest.php` |
| API Resource | `app/Http/Resources/Order/OrderResource.php` |
| Policy | `app/Policies/Order/CommonPolicy.php` |
| Repository (read) | `app/Repositories/Order/OrderReadRepository.php` |
| Repository (write) | `app/Repositories/Order/OrderStoreRepository.php` |
| Service | `app/Services/Order/WorkflowService.php` |
| Observer | `app/Observers/Order/Observer.php` |
| Event | `app/Events/Order/StatusChanged.php` |
| Listener | `app/Listeners/Order/Notifications/SendStatusChanged.php` |
| Notification | `app/Notifications/Order/Notification.php` |
| Exception | `app/Exceptions/Order/OrderAccessException.php` |
| DTO (форма) | `app/Dto/Order/Form/Form.php` |
| DTO (view) | `app/Dto/Order/View/ListItemView.php` |
| DTO (mapper) | `app/Dto/Order/Mapper/ViewMapper.php` |
| DTO (command) | `app/Dto/Actions/Order/Common/StoreCommand.php` |
| Action | `app/Actions/Order/Common/StoreAction.php` |
| Filament Resource | `app/Filament/Resources/Order/Orders/OrderResource.php` |
| Feature-тест | `tests/Feature/Order/StoreOrderTest.php` |
| Unit-тест | `tests/Unit/Order/WorkflowServiceTest.php` |

## Зеркало `database/`

```
database/
├── factories/
│   ├── Order/                  ← зеркалит app/Models/Order/
│   │   ├── OrderFactory.php
│   │   ├── ItemFactory.php
│   │   └── HistoryFactory.php
│   ├── Document/
│   └── UserFactory.php         ← одиночная модель без доменной папки в Models — допустимо и в factories
├── seeders/
│   ├── DatabaseSeeder.php
│   ├── OrderSeeder.php         ← плоско, префикс = домен
│   └── UserRolesSeeder.php
└── migrations/                 ← плоско (порядок по времени), имя таблицы с доменным префиксом: order_orders, order_items
```

Фабрика лежит в той же доменной подпапке, что и модель: `Models/Order/Item.php` ↔ `factories/Order/ItemFactory.php`.

## Зеркало `tests/`

```
tests/
├── Feature/
│   ├── Order/                  ← HTTP/use-case тесты домена
│   ├── Document/
│   ├── User/
│   ├── Auth/                   ← технические Feature-домены допустимы (Auth, Health, Layout)
│   └── Health/
├── Unit/
│   ├── Order/                  ← сервисы, DTO, enum домена
│   ├── User/
│   └── Broadcast/              ← техническая ось зеркалится тоже: Services/Broadcast → Unit/Broadcast
├── Browser/                    ← E2E (Dusk): Pages/, Components/
└── Support/                    ← инфраструктура тестов
    ├── Assertions/
    ├── Concerns/
    └── Factories/
```

Правило: тест ищется по тому же домену, что и тестируемый класс. `app/Services/Order/WorkflowService.php` → `tests/Unit/Order/WorkflowServiceTest.php`; `POST /orders` → `tests/Feature/Order/StoreOrderTest.php`.

## Чеклист «добавляю новый домен»

Минимальный комплект (создавать только то, что нужно сейчас, без пустых папок):

- [ ] `app/Models/<Domain>/<Entity>.php` — модель в доменной папке с первого дня
- [ ] `database/migrations/*_create_<domain>_<entities>_table.php` — таблица с доменным префиксом
- [ ] `database/factories/<Domain>/<Entity>Factory.php`
- [ ] `app/Enums/<Domain>/` — статусы/типы, если есть
- [ ] `app/Http/Controllers/<Domain>/` + `Requests/<Domain>/` — если есть HTTP-слой
- [ ] `app/Policies/<Domain>/CommonPolicy.php` — если есть авторизация
- [ ] `tests/Feature/<Domain>/` — первый тест вместе с первым эндпоинтом
- [ ] Имя домена одинаково во всех слоях (единственное число, PascalCase)
- [ ] Нет файлов домена в плоских корнях (`app/Models/X.php`, `app/Exceptions/XException.php`)

## Чеклист «добавляю подпроцесс»

Подпроцесс (например, `Review`) появляется синхронно в четырёх местах:

- [ ] `app/Actions/<Domain>/Review/` — действия подпроцесса
- [ ] `app/Dto/Actions/<Domain>/Review/` — их Command DTO
- [ ] `app/Enums/<Domain>/Permissions/ReviewPermission.php` — права подпроцесса
- [ ] `app/Policies/<Domain>/ReviewPolicy.php` — политика подпроцесса
- [ ] При наличии стадий: `app/Enums/<Domain>/Workflow/ReviewStage.php`
- [ ] Имя подпроцесса одинаково во всех четырёх слоях
