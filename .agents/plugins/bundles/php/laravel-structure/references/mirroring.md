> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Mirror Rule

**One domain taxonomy in all layers.** If the domain is called `Order` in `Models/`, it's called `Order` in `Enums/`, `Policies/`, `Repositories/`, `database/factories/`, `tests/Feature/` — everywhere. Subprocesses (`Common/`, `Review/`, `Application/`) — identical subfolders in `Actions/`, `Dto/Actions/`, `Enums/<Domain>/Permissions/`, `Policies/<Domain>/`.

Mirror is navigation: knowing one entity file, developer (and agent) calculates the paths of all others without searching.

## One entity Order — all her files by layers

| Layer | Path |
|---|---|
| Model | `app/Models/Order/Order.php` |
| Factory | `database/factories/Order/OrderFactory.php` |
| Cider | `database/seeders/OrderSeeder.php` |
| Migration | `database/migrations/2026_01_01_000000_create_order_orders_table.php` |
| Enum (status) | `app/Enums/Order/OrderStatus.php` |
| Enum (rights) | `app/Enums/Order/Permissions/CommonPermission.php` |
| Controller | `app/Http/Controllers/Order/OrdersController.php` |
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
| DTO (form) | `app/Dto/Order/Form/Form.php` |
| DTO (view) | `app/Dto/Order/View/ListItemView.php` |
| DTO (mapper) | `app/Dto/Order/Mapper/ViewMapper.php` |
| DTO (command) | `app/Dto/Actions/Order/Common/StoreCommand.php` |
| Action | `app/Actions/Order/Common/StoreAction.php` |
| Filament Resource | `app/Filament/Resources/Order/Orders/OrderResource.php` |
| Feature-test | `tests/Feature/Order/StoreOrderTest.php` |
| Unit-test | `tests/Unit/Order/WorkflowServiceTest.php` |

## Mirror `database/`

```
database/
├── factories/
│   ├── Order/                  ← mirrors app/Models/Order/
│   │   ├── OrderFactory.php
│   │   ├── ItemFactory.php
│   │   └── HistoryFactory.php
│   ├── Document/
│   └── UserFactory.php         ← single model without domain folder in Models — is also valid in factories
├── seeders/
│   ├── DatabaseSeeder.php
│   ├── OrderSeeder.php         ← flat, prefix = domain
│   └── UserRolesSeeder.php
└── migrations/                 ← flat (order by time), table name with domain prefix: order_orders, order_items
```

The factory is located in the same domain subfolder as the model: `Models/Order/Item.php` ↔ `factories/Order/ItemFactory.php`.

## Mirror `tests/`

```
tests/
├── Feature/
│   ├── Order/                  ← HTTP/use-case domain tests
│   ├── Document/
│   ├── User/
│   ├── Auth/                   ← technical Feature-domains are allowed (Auth, Health, Layout)
│   └── Health/
├── Unit/
│   ├── Order/                  ← services, DTO, enum domain
│   ├── User/
│   └── Broadcast/              ← the technical axis is also mirrored: Services/Broadcast → Unit/Broadcast
├── Browser/                    ← E2E (Dusk): Pages/, Components/
└── Support/                    ← test infrastructure
    ├── Assertions/
    ├── Concerns/
    └── Factories/
```

Rule: the test is searched for the same domain as the class being tested. `app/Services/Order/WorkflowService.php` → `tests/Unit/Order/WorkflowServiceTest.php`; `POST /orders` → `tests/Feature/Order/StoreOrderTest.php`.

## Checklist «adding a new domain»

Minimum kit (create only what is needed now, without empty folders):

- [ ] `app/Models/<Domain>/<Entity>.php` — model in the domain folder from day one
- [ ] `database/migrations/*_create_<domain>_<entities>_table.php` — table with domain prefix
- [ ] `database/factories/<Domain>/<Entity>Factory.php`
- [ ] `app/Enums/<Domain>/` — statuses/types, if any
- [ ] `app/Http/Controllers/<Domain>/` + `Requests/<Domain>/` — if available HTTP-layer
- [ ] `app/Policies/<Domain>/CommonPolicy.php` — if there is authorization
- [ ] `tests/Feature/<Domain>/` — first test along with the first endpoint
- [ ] Domain name is the same in all layers (singular, PascalCase)
- [ ] No domain files in flat roots (`app/Models/X.php`, `app/Exceptions/XException.php`)

## Checklist «adding a subprocess»

Subprocess (for example, `Review`) appears synchronously in four places:

- [ ] `app/Actions/<Domain>/Review/` — subprocess actions
- [ ] `app/Dto/Actions/<Domain>/Review/` — them Command DTO
- [ ] `app/Enums/<Domain>/Permissions/ReviewPermission.php` — subprocess rights
- [ ] `app/Policies/<Domain>/ReviewPolicy.php` — subprocess policy
- [ ] If there are stages: `app/Enums/<Domain>/Workflow/ReviewStage.php`
- [ ] Subprocess name is the same in all four layers
