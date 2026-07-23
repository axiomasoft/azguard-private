---
name: php-naming-conventions
bucket: php
version: 0.1.0
description: "Naming Laravel/PHP entities: repository methods by intent (not findByCodeOrFail-explosion), Action/DTO/VO/Enum/Event/Job, tables/columns/routes. Kills junk names AI loves to generate. Activate when creating or reviewing class/method/migration names."
risk: read
persona: oss-dev
tags: ["php", "laravel", "naming", "conventions", "code-style", "readability"]
requires: []
produces_for: []
outputs: []
snippets: ["naming-antipatterns.php", "repository-method-naming.php"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel/PHP Entity Naming

Name = intent, not implementation. Base + cross-language rules: `general/naming-conventions`. Here: PHP/Laravel specifics. Not `code-style-spatie` (route/controller style), not `named-arguments` (call style); this is entity names (classes, methods, fields).

## Activate when

- Creating/reviewing a class name (Action, Repository, DTO, VO, Service, Enum, Event, Job).
- Naming a repository/service/model method or scope.
- Writing a migration (tables, columns, keys).
- A generated name doesn't reveal intent.

## Anti-pattern: `findByX…OrFail` explosion

Method-per-field = exponential growth + duplication:

```php
// ❌ junk: one method per column, error policy duplicated N times in the name
public function findByCodeOrFail(string $code): Order { /* … */ }
public function findByEmailOrFail(string $email): Order { /* … */ }
public function findBySlugOrFail(string $slug): Order { /* … */ }
```

Replace with (preference order):

1. **Route model binding** — controllers: Laravel finds by key (`getRouteKeyName()` for non-id); no repo method needed.
2. **Model scope by intent** — repeated predicate → `scopeActive`, `scopeForUser`; repo composes them. Scope name = business meaning, not column.
3. **One finder + criterion**: `findBy(Criteria $c)` / specification, not method-per-field.
4. **Eloquent already does** `firstWhere('code', $code)`, `findOrFail($id)`, `where(...)->firstOrFail()` — don't wrap the trivial.

Allowed: a single canonical `findByIdOrFail` over `queryForUser()` (see `repositories`) and named domain queries (`pendingForReview()`) when the selection is domain-meaningful. Line: by intent/business meaning — yes; per column — no.

## Naming by entity type

| Entity | Rule | Example |
|:--|:--|:--|
| **Action** | imperative + object, one public `__invoke`/`execute`; no `Manager`/`Service` tail | `CreateOrder`, `PublishDocument`, `CancelSubscription` |
| **Repository** | `<Domain>ReadRepository` / `<Domain>StoreRepository`; methods by *intent* | `forUser()`, `pendingForReview()`, `withListRelations()` |
| **DTO / Form** | suffix `Data` (data) or `Form` (command input); not `DTO`/`Object` | `OrderData`, `CreateOrderForm` |
| **Value Object** | domain noun, no suffix | `Money`, `EmailAddress`, `DateRange` |
| **Service** | `<Role>Service` only with real responsibility; else Action/Repository | `VisibilityService`, `PriceCalculator` (not `OrderManager`) |
| **Enum** | singular name, cases PascalCase | `OrderStatus::Pending`, `Role::Admin` |
| **Event** | past tense (fact happened) | `OrderShipped`, `InvoicePaid` |
| **Listener / Job** | imperative (what to do) | `SendShipmentNotification`, `ProcessPayment` |
| **Model scope** | `scope` + domain intent | `scopeActive`, `scopeForUser`, `scopeWithStatus` |
| **Policy** | `<Model>Policy`, methods = ability (`view`, `update`, `delete`) | `OrderPolicy::update()` |
| **Controller/route** | see `code-style-spatie` (resource plural, kebab URL, camelCase route) | `OrdersController` |

## Database (migrations)

- Tables — snake_case **plural** (`orders`, `order_items`); pivot — two models singular, alphabetical (`role_user`).
- Columns — snake_case; FK — `<single>_id` (`user_id`); booleans — `is_`/`has_` (`is_active`); date/time — `_at` (`published_at`), `_date`/`_on` for dates.
- No `tbl_`/`col_` prefixes, no Hungarian notation.

## Quality checklist

- [ ] No `findByX…OrFail` per field; lookup via binding/scope/criterion or one canonical lookup.
- [ ] Action — imperative + object, one public method; no `Manager`/`Helper`/`Util`/`Processor` classes without a role.
- [ ] DTO — `Data`/`Form`; VO — domain noun without type suffix.
- [ ] Enum singular, cases PascalCase; Event — past tense; Job/Listener — imperative.
- [ ] Repository methods named by *intent* (what we select), not SQL mechanics.
- [ ] Tables plural snake_case; FK `_id`, booleans `is_/has_`, time `_at`.
- [ ] Drop redundant `get`/type suffix (`user(id)`, not `getUserObjectById`).

## Which snippet to open

| Situation | File |
|:--|:--|
| Need a summary "bad → good" table across all Laravel entity types | `snippets/naming-antipatterns.php` |
| Refactoring a repository with scattered `findByX` into scope/criterion | `snippets/repository-method-naming.php` |

## Links

- `general/naming-conventions` — language-agnostic principles (intent, noise words, CQS).
- `php/repositories` — read/store split, `queryForUser`, scopes (where named queries live).
- `php/code-style-spatie` — code style and route/controller/config naming.
- `php/named-arguments` — named arguments on the call side.
- `php/php-patterns` — VO/DTO/specification (where the "criterion" goes instead of `findByX`).
- `php/laravel-structure` — where classes live in the project tree.

<!-- ru-source-sha256: 7976420e69ed3fe3f9196b80da57a1230b8808b8a779c336cd8fc315306df125 -->
