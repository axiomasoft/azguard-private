---
name: laravel-structure
bucket: php
version: 0.2.0
description: "Laravel project structure canon for greenfield and adding domains: domain taxonomy, layer-mirror rule, class placement and naming"
risk: write
persona: oss-dev
tags: ["php", "laravel", "architecture", "structure"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel Structure

Canon for where to put files and how to name them (implementation skills answer "how to write the class"). Apply at project start, when adding a domain/subprocess, and in placement reviews. For greenfield + adding domains; at tens of domains / multiple teams switch to `laravel-architecture/modular-architecture` (`app/Modules/`).

## Principles

1. **Two organization axes.**
   - Domain axis (business code): in `Actions/`, `Models/`, `Enums/`, `Dto/`, `Events/`, `Exceptions/`, `Policies/`, `Repositories/`, `Services/`, `Http/{Controllers,Requests,Resources}/`, `Filament/Resources/`, `Notifications/`, `Observers/`, `Listeners/`, `Jobs/` — always domain subfolders (`Order/`, `Document/`, `User/`).
   - Technical axis (infra): `Concerns/<Tech>`, `Attributes/Common`, `Support/<Tech>`, `Utils/`, `TypeScript/`, `MediaLibrary/`, `Health/`, `Services/<Tech>` (Broadcast/Log/Layout). Technical axis must not know about domains.
2. **Mirror rule.** One domain taxonomy across all layers: `Models/Order` ↔ `Enums/Order` ↔ `Policies/Order` ↔ `Repositories/Order` ↔ `factories/Order` ↔ `tests/Feature/Order`. Subprocesses (`Common/`, `Review/`, `Application/`) — identical subfolders in `Actions/`, `Dto/Actions/`, `Enums/<Domain>/Permissions/`, `Policies/<Domain>/`.
3. **No consumer level.** NOT `Enums/Models/<Domain>`. Exception — single-consumer rule: `Dto/Actions/<Domain>` mirrors `Actions/<Domain>` (Command DTO has exactly one consumer — its Action); Mapper lives next to View DTO.
4. **Grow inward.** Semantic subfolders inside the domain (`Enums/Order/Workflow/`, `Enums/Order/Permissions/`, `Services/Order/Access/`, `Services/Order/Store/`), not new root axes. No empty "for-growth" folders.

## Class placement

| What | Where | Example |
|---|---|---|
| Model | `app/Models/<Domain>/` | `Models/Order/Order.php` |
| Enum status / event code | `app/Enums/<Domain>/` | `Enums/Order/OrderStatus.php` |
| Domain permissions (permission-enum) | `app/Enums/<Domain>/Permissions/` | `Enums/Order/Permissions/CommonPermission.php` |
| Use-case (Action) | `app/Actions/<Domain>/<Subprocess>/` | `Actions/Order/Common/StoreAction.php` |
| Command DTO | `app/Dto/Actions/<Domain>/<Subprocess>/` | `Dto/Actions/Order/Common/StoreCommand.php` |
| View DTO | `app/Dto/<Domain>/View/` | `Dto/Order/View/ListItemView.php` |
| DTO Mapper | `app/Dto/<Domain>/Mapper/` | `Dto/Order/Mapper/ViewMapper.php` |
| Form DTO | `app/Dto/<Domain>/Form/` | `Dto/Order/Form/Form.php` |
| Domain service | `app/Services/<Domain>/[<Aspect>/]` | `Services/Order/Access/OrderAccessEvaluator.php` |
| Infra service | `app/Services/<Tech>/` | `Services/Broadcast/ChannelManager.php` |
| Read repository | `app/Repositories/<Domain>/` | `Repositories/Order/OrderReadRepository.php` |
| Store repository | `app/Repositories/<Domain>/` | `Repositories/Order/OrderStoreRepository.php` |
| Policy | `app/Policies/<Domain>/` | `Policies/Order/CommonPolicy.php` |
| Event | `app/Events/<Domain>/` | `Events/Order/StatusChanged.php` |
| Listener | `app/Listeners/<Domain>/[<Purpose>/]` | `Listeners/Order/Notifications/SendStatusChanged.php` |
| Exception | `app/Exceptions/<Domain>/` | `Exceptions/Order/OrderAccessException.php` |
| Job | `app/Jobs/<Domain>/` | `Jobs/User/SyncProfileJob.php` |
| Notification | `app/Notifications/<Domain>/` | `Notifications/Order/Notification.php` |
| Observer | `app/Observers/<Domain>/` | `Observers/Order/Observer.php` |
| Filament resource | `app/Filament/Resources/<Domain>/<Models>/` | `Filament/Resources/Order/Orders/OrderResource.php` |
| Trait mixin | `app/Concerns/<Tech>/` | `Concerns/Enums/HasLabelAttribute.php` |
| PHP attribute | `app/Attributes/Common/` or `Attributes/<Domain>/` | `Attributes/Common/Label.php` |
| Static helper | `app/Support/<Tech>/` or `app/Utils/` | `Support/Enums/EnumCaseAttributeResolver.php` |
| Page-shell UI DTO | `app/Dto/Layout/View/` (UI pseudo-domain) | `Dto/Layout/View/SharedPageProps.php` |
| Factory | `database/factories/<Domain>/` | `factories/Order/OrderFactory.php` |
| Seeder | `database/seeders/` (prefix = domain) | `seeders/OrderSeeder.php` |
| Feature test | `tests/Feature/<Domain>/` | `Feature/Order/StoreOrderTest.php` |
| Unit test | `tests/Unit/<Domain>/` | `Unit/Order/WorkflowServiceTest.php` |

## Naming

| Type | Pattern | Example |
|---|---|---|
| Action | `VerbNounAction` | `StoreAction`, `TakeInWorkAction` |
| Command DTO | `VerbNounCommand` | `StoreCommand`, `ReplyCommand` |
| View DTO | `XxxView` | `ListItemView`, `DetailExtraView` |
| Form DTO | `Form` (+ parts) | `Form`, `Items` |
| Service | `XxxService` / `XxxEvaluator` | `WorkflowService`, `OrderAccessEvaluator` |
| Repository | `XxxReadRepository` / `XxxStoreRepository` | `OrderReadRepository`, `OrderStoreRepository` |
| Policy | `<Subprocess>Policy`, methods `canXxx` | `CommonPolicy::canEdit` |
| Exception | `XxxException` | `OrderAccessException` |
| Event | past tense, no domain prefix | `StatusChanged`, `Created` |
| Concern | `HasXxx` | `HasLabelAttribute` |

## Which reference to open

| Situation | File |
|---|---|
| Full `app/` tree, per-folder rules and errors, Filament canon | `references/app-structure.md` |
| "domain → layer" principle, subprocesses, prohibitions, domain growth | `references/domain-structure.md` |
| All files of one entity across layers, `database/` & `tests/` mirror, "new domain" / "new subprocess" checklists | `references/mirroring.md` |
| Where to extract shared code: scope → Concern → attribute → Support → Utils → DTO → package | `references/shared-code.md` |

## Quality checklist

- [ ] New class placed per placement table; named per naming table
- [ ] Domain taxonomy mirrored: same domain in `Models/`, `Enums/`, `Policies/`, `factories/`, `tests/`
- [ ] No consumer level (`Enums/Models/...`) — except `Dto/Actions/`
- [ ] Technical axis (`Concerns/`, `Support/`, `Utils/`) mentions no domains
- [ ] No empty folders/files in flat roots (`app/Models/X.php`, `app/Exceptions/XException.php`)
- [ ] Subprocess added in sync across `Actions/`, `Dto/Actions/`, `Enums/Permissions/`, `Policies/`

## Related skills

- Action/DTO/layer implementation: `php/laravel` → `snippets/layer-boundaries.md`, `snippets/dto.md`, `snippets/actions.md`
- Read/Store repositories: `php/repositories`
- Dependency injection and binding: `php/dependency-injection`
- Attributes, Concern traits, resolver, local package: `laravel-architecture/enum-attributes`
- General architecture rules: `php/laravel-best-practices` → `references/architecture.md`
- Growth to modules (tens of domains, multiple teams): `laravel-architecture/modular-architecture`
- Migrations, models, table naming: `php/database`
- Per-domain tests (`tests/` mirror): `laravel-testing/laravel-testing`

<!-- ru-source-sha256: cf7fc91ba352b8207643f50c29ec75481e665cfbb89deddc947d9a668a320be0 -->
