> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel Domain Structure

## Principle

**First the domain, then the layer.** Business code is organized by business entity (domain), and not by technical type. Layer defines the root folder (`Models/`, `Policies/`, `Actions/`), domain - the first subfolder inside it.

```
✅ app/Models/Order/Order.php
✅ app/Policies/Order/CommonPolicy.php
✅ app/Actions/Order/Common/StoreAction.php

❌ app/Models/Order.php          — flat list without domain
❌ app/Policies/OrderPolicy.php  — domain logic without folder
```

A domain is a durable business entity with its own life cycle (Document, Order, User, Notification), and not a screen, not a controller, and not a database table.

## Canonical structure `app/` (domain axis)

```
app/
├── Actions/<Domain>/<Subprocess>/   ← use-case entry points
├── Dto/
│   ├── <Domain>/                    ← Form, View, Abilities, Mapper, Repository
│   └── Actions/<Domain>/            ← Command DTO for Actions (single consumer rule)
├── Enums/<Domain>/                  ← statuses, roles, event codes, rights
├── Events/<Domain>/                 ← domain events
├── Exceptions/<Domain>/             ← domain exceptions
├── Filament/Resources/<Domain>/     ← admin resources
├── Http/
│   ├── Controllers/<Domain>/
│   ├── Requests/<Domain>/           ← Form Requests
│   └── Resources/<Domain>/          ← API Resources
├── Jobs/<Domain>/
├── Listeners/<Domain>/
├── Models/<Domain>/
├── Notifications/<Domain>/
├── Observers/<Domain>/
├── Policies/<Domain>/
├── Repositories/<Domain>/           ← *ReadRepository (read) + *StoreRepository (write)
└── Services/<Domain>/
```

Technical axis (`Concerns/`, `Support/`, `Utils/`, `Attributes/Common/`, `Services/<Tech>/`) is described in `app-structure.md`.

## Mirror Rule

If the entity is in `app/Models/Order/Order.php` — all her neighbors live in `.../Order/`:

| Layer | Path |
|:---|:---|
| Model | `app/Models/Order/Order.php` |
| Enum | `app/Enums/Order/OrderStatus.php` |
| Controller | `app/Http/Controllers/Order/OrdersController.php` |
| Policy | `app/Policies/Order/CommonPolicy.php` |
| Repository (read) | `app/Repositories/Order/OrderReadRepository.php` |
| Repository (write) | `app/Repositories/Order/OrderStoreRepository.php` |
| Service | `app/Services/Order/WorkflowService.php` |
| Observer | `app/Observers/Order/Observer.php` |
| Exception | `app/Exceptions/Order/OrderAccessException.php` |
| DTO (form) | `app/Dto/Order/Form/Form.php` |
| Action | `app/Actions/Order/Common/StoreAction.php` |

Full mirror table (including `database/` and `tests/`) — in `mirroring.md`.

## Without consumer level

Subfolders within a layer are named by domain, **not by consumer**:

```
✅ app/Enums/Order/OrderStatus.php
❌ app/Enums/Models/Order/OrderStatus.php   — «for models» lies: enum read
                                              policies, DTO, Filament, frontend
```

U enum, events, exceptions, many consumers, and their list changes; the domain is stable. The only legal exception is **single consumer rule**: `Dto/Actions/<Domain>/` mirrors `Actions/<Domain>/`, because Command DTO has exactly one consumer - its Action. By the same logic `Dto/<Domain>/Mapper/` lives next to View DTO, which he collects.

## Sub-process division into Actions and DTO

For complex domains Actions are divided into business processes, and this breakdown **is mirrored** in Dto/Actions, Enums/Permissions and Policies:

```
app/Actions/Order/
├── Common/        ← save form, get to work, general transitions
├── Review/        ← approval, approval, return for revision
└── Application/   ← processing of the application by an external contractor

app/Dto/Actions/Order/{Common,Review,Application}/
app/Enums/Order/Permissions/{CommonPermission,ReviewPermission,ApplicationPermission}.php
app/Policies/Order/{CommonPolicy,ReviewPolicy,ApplicationPolicy}.php
```

## Domain growth

When the domain swells - meaningful subfolders **inside** domain, not new root axes:

```
app/Enums/Order/Workflow/      ← process stages (CommonStage, ReviewStage)
app/Enums/Order/Permissions/   ← rights for subprocesses
app/Services/Order/Access/     ← access: evaluator, visibility, rules
app/Services/Order/Store/      ← persistence: sync, persistence
app/Repositories/Order/Media/  ← domain attachments and files
```

## Class Naming

| Type | Template | Example |
|:---|:---|:---|
| Action | `VerbNounAction` | `StoreAction`, `RegisteredAction` |
| Command DTO | `VerbNounCommand` | `StoreCommand`, `WrittenReplyCommand` |
| Policy | `<Subprocess>Policy`, methods `can<Action>` | `CommonPolicy::canEdit` |
| View DTO | suffix `View` | `ListItemView`, `DetailExtraView` |
| Form DTO | `Form` (+ nested parts) | `Form`, `Items` |
| Repository (read) | `*ReadRepository` | `OrderReadRepository` |
| Repository (write) | `*StoreRepository` | `OrderStoreRepository` |
| Service | `*Service`, `*Evaluator`, `*Allocator` | `WorkflowService`, `AccessEvaluator` |
| Exception | `*Exception` | `OrderAccessException` |

## Prohibitions

- **Not possible** add domain logic outside `*/<Domain>/*`, if it is not an infrastructure layer.
- **Not possible** use folder `Common/` for logic that belongs to a specific domain.
- **Not possible** create a class without checking whether there is already a similar one in the domain.
- **Not possible** keep empty domain folders «for growth The» — folder appears along with the first class.

## Navigation rule

> If the entity is in `app/Models/<Domain>/`, her Policy, Service, Repository, Controller, DTO, Exception — all in `.../<Domain>/`.

## PR-checklist

- [ ] The new file is in the domain folder of its layer
- [ ] Adjacent layers are synchronized (Model / Policy / Service / DTO / tests)
- [ ] Subfolder named by domain or subprocess, not consumer
- [ ] No old imports from legacy-paths after rename
