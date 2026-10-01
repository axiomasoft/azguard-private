> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Structure Canon `app/`

Two axes of organization:

- **Domain axis** — business code. Root folder = layer, first subfolder = domain (Document, Order, User…). Mirror rule in effect (see `mirroring.md`).
- **Technical axis** — infrastructure reused by all domains. Subfolders are named according to technology/mechanism (`Concerns/Enums`, `Support/Auth`), not by domain.

A class falls on the technical axis only if it does not know about any domain. As soon as it appears inside `Order`-specifics - moving to a domain folder.

## Full tree (standard)

```
app/
├── Actions/                # D: use-cases — Actions/Order/Common/StoreAction.php
├── Attributes/             # T+D: PHP-attributes - Common/ + <Domain>/
├── Concerns/               # T: traits-mixins - Concerns/Enums/, Concerns/Media/
├── Console/Commands/       # T: artisan-commands
├── Dto/                    # D: Dto/<Domain>/{Form,View,Abilities,Mapper} + Dto/Actions/<Domain>/
├── Enums/                  # D: Enums/Order/{OrderStatus,EventCode,Workflow/,Permissions/}
├── Events/                 # D: Events/Order/{Created,StatusChanged}.php
├── Exceptions/             # D: Exceptions/Order/OrderAccessException.php
├── Filament/               # D: Resources/<Domain>/<Models>/{Pages,Schemas,Tables}
├── Health/                 # T: health-checks (Checks/)
├── Http/
│   ├── Controllers/        # D: Controllers/Order/OrdersController.php
│   ├── Middleware/         # T: through middleware
│   ├── Requests/           # D: Requests/Order/StoreOrderRequest.php
│   ├── Resources/          # D: Resources/Order/OrderResource.php
│   └── Support/            # T: helpers HTTP-layer
├── Jobs/                   # D: Jobs/User/SyncProfileJob.php
├── Listeners/              # D: Listeners/Order/Notifications/...
├── MediaLibrary/           # T: path generators, conversions
├── Models/                 # D: Models/Order/{Order,Item,History}.php
├── Notifications/          # D: Notifications/Order/Notification.php
├── Observers/              # D: Observers/Order/Observer.php
├── Policies/               # D: Policies/Order/{Common,Review}Policy.php
├── Providers/              # T: service providers (+ Filament/)
├── Repositories/           # D: Repositories/Order/{OrderRead,OrderStore}Repository.php
├── Services/               # D+T: Services/Order/ AND Services/{Broadcast,Log,Layout}/
├── Settings/               # D: spatie settings by domain
├── Support/                # T: Support/{Auth,Enums}/ — static mechanisms
├── TypeScript/             # T: transformers for generating types
└── Utils/                  # T: pure helpers Date/Str/Enum
```

`D` — domain axis, `T` — technical.

## Domain axis folders

| Folder | Rule | Example | Typical error |
|---|---|---|---|
| `Actions/<Domain>/<Subprocess>/` | Use-case = one Action; subprocesses Common/Review/Application | `Actions/Order/Common/StoreAction.php` | One God-Action for the entire domain; Action in `app/Actions/` without domain |
| `Dto/<Domain>/` | Buckets Form/View/Abilities/Repository; Mapper next to View | `Dto/Order/View/ListItemView.php`, `Dto/Order/Mapper/ViewMapper.php` | Dump all DTO domain in one folder without buckets |
| `Dto/Actions/<Domain>/<Subprocess>/` | Mirror `Actions/`; single consumer rule | `Dto/Actions/Order/Common/StoreCommand.php` | Put Command in `Dto/Order/` — connection with Action |
| `Enums/<Domain>/` | Statuses, event codes; subfolders Workflow/, Permissions/, View/ | `Enums/Order/OrderStatus.php`, `Enums/Order/Permissions/CommonPermission.php` | `Enums/Models/Order/` — consumer level disabled |
| `Events/<Domain>/` | Name - past tense without domain prefix | `Events/Order/StatusChanged.php` | `OrderStatusChangedEvent` in a flat folder |
| `Exceptions/<Domain>/` | Domain exclusions by domain (flat `app/Exceptions/` — disadvantage legacy-projects) | `Exceptions/Order/OrderAccessException.php` | Save all exceptions to the root `Exceptions/` |
| `Http/Controllers/<Domain>/` | Slim controller: Request → Action/Repository → Response | `Http/Controllers/Order/OrdersController.php` | Business logic in the controller |
| `Http/Requests/<Domain>/` | Form Request for each mutating operation | `Http/Requests/Order/StoreOrderRequest.php` | Validation in controller |
| `Http/Resources/<Domain>/` | API Resources | `Http/Resources/Order/OrderResource.php` | Manual assembly of arrays in the controller |
| `Jobs/<Domain>/` | Upcoming domain tasks | `Jobs/User/SyncProfileJob.php` | Job with logic - Job only calls Action/Service |
| `Listeners/<Domain>/` | Domain event listeners; growth - subfolders by purpose | `Listeners/Order/Notifications/SendStatusChanged.php` | Listener for someone else's domain in your folder |
| `Models/<Domain>/` | Unit + related models together | `Models/Order/{Order,Item,History}.php` | Satellite model (History) at the root `Models/` |
| `Notifications/<Domain>/` | Domain Notifications | `Notifications/Order/Notification.php` | — |
| `Observers/<Domain>/` | One Observer per model | `Observers/Order/Observer.php` | Logic in Observer instead of calling Service |
| `Policies/<Domain>/` | Policy by subprocesses, methods `can*` | `Policies/Order/CommonPolicy.php` | One `OrderPolicy` for 30 methods |
| `Repositories/<Domain>/` | Read/Store separately; growth - subfolders (`Media/`) | `Repositories/Order/OrderReadRepository.php` | Requests Eloquent spread across controllers |
| `Services/<Domain>/` | Domain logic; growth - `Access/`, `Store/`, `<Subprocess>/` | `Services/Order/Access/OrderAccessEvaluator.php` | Universal `OrderService` for everything |
| `Settings/<Domain>/` | Settings classes (spatie/laravel-settings) | `Settings/Order/WorkflowSettings.php` | Settings in config/ instead Settings |

## Technical axis folders

| Folder | Rule | Example | Typical error |
|---|---|---|---|
| `Attributes/Common/` + `Attributes/<Domain>/` | Generic attributes in Common, domain - by domain | `Attributes/Common/Label.php`, `Attributes/Order/Stage.php` | Domain attribute in Common |
| `Concerns/<Tech>/` | Traits mixin of metadata and behavior, NOT business logic; grouping by mechanism | `Concerns/Enums/HasLabelAttribute.php`, `Concerns/Media/HasMediaPathAttribute.php` | Trait with business logic as a way «fumble» code between domains |
| `Support/<Tech>/` | Static mechanisms without state and dependencies (reflection-resolvers, recorders) | `Support/Enums/EnumCaseAttributeResolver.php`, `Support/Auth/PolicyAttributeRegistrar.php` | Injected service into Support (he belongs in Services) |
| `Utils/` | Pure helper functions without Laravel-dependencies | `Utils/DateHelper.php`, `Utils/StrHelper.php` | Helper that pulls the database or container |
| `Services/<Tech>/` | Infrastructure services: Broadcast, Log, Layout | `Services/Broadcast/ChannelManager.php`, `Services/Layout/SharedPropsService.php` | Domain logic in technical service |
| `TypeScript/` | Transformers/type generation collectors for the frontend | `TypeScript/EnumTransformer.php` | — |
| `MediaLibrary/` | Path generators, conversions for spatie/medialibrary | `MediaLibrary/Support/PathGenerator.php` | — |
| `Health/Checks/` | Custom health-checks | `Health/Checks/QueueCheck.php` | — |
| `Http/Middleware/`, `Http/Support/` | Pass-through middleware and helpers HTTP-layer | `Http/Middleware/HandleInertiaRequests.php` | Domain middleware (better Policy/Gate) |
| `Console/Commands/` | Artisan-commands; the command only calls Action/Service | `Console/Commands/PruneLogsCommand.php` | Logic inside the command |
| `Providers/` | Service Providers (+ `Providers/Filament/` for panels) | `Providers/AppServiceProvider.php` | — |

## Pseudo-domain Layout (UI-layer)

UI-DTO, which describe not the business entity, but the page shell (shared props Inertia, menu, banner impersonation), live in a dedicated pseudo-domain **Layout** — this is obvious UI-layer, not business domain:

```
app/Dto/Layout/View/SharedPageProps.php
app/Dto/Layout/View/MenuItem.php
app/Services/Layout/SharedPropsService.php
```

Do not take away such DTO by business domains and do not add to `Dto/Common/`.

## Filament-canon

`Filament/Resources/<Domain>/<Models>/` — domain, then plural entity, inside - Resource-class and folders Pages/Schemas/Tables (+ RelationManagers if necessary):

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

Rules:

- Resource — thin router: the form is placed in `Schemas/<Model>Form.php`, table - in `Tables/<Models>Table.php`.
- Several resources of the same domain - adjacent folders: `Order/Orders/`, `Order/Items/`.
- `Filament/Pages/` — individual panel pages (Dashboard etc.), outside the domain axis.

## What we DON'T do

- We do not keep empty domain folders «for growth The» — folder appears along with the first class.
- We do not create a consumer level (`Enums/Models/...`, `Dto/Filament/...`) — other than legalized `Dto/Actions/`.
- We do not start parallel axes (`app/Domain/Order/...` next to `app/Models/Order/...`) — one taxonomy.
- We do not put the domain code in `Support/`, `Utils/`, `Concerns/` — technical axis is not aware of domains.
