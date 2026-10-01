> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Takeaway of common code: ladder of steps

Code is displayed «up» only when duplication actually happened (second consumer exists, not assumed). Each subsequent stage is more abstraction and more maintenance costs; we take the minimum sufficient.

```
1. Scope / model method          ← code is needed in two places of the same domain
2. Concern-trait                 ← same mixin for classes of different domains
3. PHP-attribute + resolver        ← declarative metadata instead match-sheets
4. Support/<Tech>-helper         ← static stateless mechanism
5. Utils                         ← pure functions without Laravel
6. DTO instead of an array            ← common data structure between layers
7. Local package               ← code is useful outside the project
```

## Stage 1: scope / model method

Logic belongs to one entity - remains in the model:

```php
// app/Models/Order/Order.php
public function scopeActive(Builder $query): Builder
{
    return $query->whereNot('status', OrderStatus::Closed);
}
```

Don't put it in trait/helper is something that only requests for `Order`.

## Stage 2: Concern-trait

Same **metadata or behavior admixture** is needed by classes of different domains - trait in `app/Concerns/<Tech>/`, where `<Tech>` — mechanism, not domain:

```
app/Concerns/Enums/HasLabelAttribute.php     ← method getLabel() for any enum
app/Concerns/Enums/HasColorAttribute.php
app/Concerns/Media/HasMediaPathAttribute.php ← media storage path for models
```

Boundaries:

- Concern — is a mixin (accessor, metadata, small behavior), **NOT business logic**. «Fumble» the matching rule via trait is not possible - this is Service.
- Trait does not know about domains: not inside `Order`, `Document` etc.
- Grouping by mechanism: `Concerns/Enums/`, `Concerns/Media/`, not `Concerns/Order/`.

## Stage 3: PHP-attribute + resolver

`match`-sheets in cases enum (label, color, stage) are replaced by declarative attributes above the case + reflection-resolver:

```
app/Attributes/Common/Label.php                 ← universal attribute
app/Attributes/Order/Stage.php                  ← domain attribute
app/Support/Enums/EnumCaseAttributeResolver.php ← single resolver
app/Concerns/Enums/HasLabelAttribute.php        ← trait facade above the resolver
```

Full pattern (attribute, resolver, trait, domain axes) — skill `laravel-architecture/enum-attributes`.

## Stage 4: Support/<Tech>-helper

Static mechanism with reflection/registration, needed by several layers - `app/Support/<Tech>/`:

```
app/Support/Enums/EnumCaseAttributeResolver.php
app/Support/Auth/PolicyAttributeRegistrar.php
```

**When Support, and when Service:**

| | `Support/<Tech>/` | `Services/<Tech>/` or `Services/<Domain>/` |
|---|---|---|
| Status | No (static methods) | Maybe |
| Dependencies | No - does not inject, does not jerk the container | Injected via constructor (DI) |
| Testing | Direct call | Via container/moki |
| Example | Reflection-attribute resolver | `Broadcast/ChannelManager`, `Order/WorkflowService` |

If the helper needed a dependency (repository, config via DI, status) — this Service, moving to `Services/`.

## Stage 5: Utils

Pure functions without Laravel-dependencies (dates, strings, general operations on enum):

```
app/Utils/DateHelper.php
app/Utils/StrHelper.php
app/Utils/EnumHelper.php
```

Cleanliness rule: do not touch the database, container, request, auth. If it touches, it's not Utils.

## Stage 6: DTO instead of arrays

General data structure between layers (Action ↔ Controller ↔ frontend) — is not an associative array, but a typed one DTO in `app/Dto/`:

- Command DTO for Action: `Dto/Actions/<Domain>/<Subprocess>/StoreCommand.php`
- View DTO for the frontend: `Dto/<Domain>/View/ListItemView.php`
- UI-page binding: pseudodomain `Dto/Layout/View/SharedPageProps.php`

Buckets Form/View/Command/Mapper and generation TypeScript — `php/laravel` → `snippets/dto.md`.

## Stage 7: local package (path-repository)

Final stage: the code is stable, unaware of the project and useful outside of it (enum-collections, generic-resolvers) — is placed in `packages/<name>/` and connects via composer path-repository with symlink:

```json
"repositories": [
    { "type": "path", "url": "packages/enum-concern", "options": { "symlink": true } }
]
```

Connection template - `laravel-architecture/enum-attributes` → `snippets/composer-path-repo.json`; package device - skills `php/laravel-package-*`.

## Anti-takeout patterns

- **Premature abstraction**: trait/helper «for the future» with one consumer - keep the code with the consumer.
- **Trait as a business logic container**: domain rule in Concern — should be Service in `Services/<Domain>/`.
- **Domain knowledge on the technical axis**: `Support/`/`Utils/`/`Concerns/` with a mention of a specific domain - moving to the domain folder.
- **Dump Helper**: `Utils/Helper.php` with a dozen unrelated methods - fractions along the axes (Date, Str, Enum).
