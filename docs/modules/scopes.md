# Scopes plugin

The Scopes module adds assignment scopes: a place inside a tenant, such as a team or a project, where a role can be
granted. The tenant is core; the scope behavior is this plugin. A panel without it accepts only global requests.

```php
use AzGuard\Scopes\ScopesPlugin;

->plugins([ScopesPlugin::inherit(Team::class)])
```

`inherit`, `isolated` and `required` set the mode ([the table](/guides/tenants-and-scopes#assignment-scopes)). The
arguments are scope definitions: an Eloquent model class (model-backed), a definition object, or a definition class
that can be created without arguments.

## What the plugin contributes

It adds, to the panel it is attached to and to no other:

- the scope declaration (mode and definitions) and the evaluator that applies filters and adapters when a scope is
  checked or a list is narrowed;
- the default directory of the queryable definitions, which lists the contexts a person can be granted in;
- the restriction `membership` (reason `assignment_scope_membership`) when you call `requireMembership()`;
- the doctor check `scopes.membership`.

The core knows scopes only as values (`AccessScope`, `AssignmentScopeRef`) and the contracts it asks (a declaration, an
evaluator, a directory, a description). It never names this module, so your own plugin can supply the same
contributions.

## Options

```php
ScopesPlugin::inherit(Team::class)
    ->requireMembership(TeamMembership::class)        // AssignmentScopeMembership
    ->accessAdapter('team', TeamAccessAdapter::class);  // AssignmentScopeAccessAdapter, for a listed type
```

- A membership or adapter is a static closure, a class name or an object that holds no non-static closure. A class
  name needs a container binding; `azguard:doctor` reports one it cannot create (`scopes.membership`), and the check
  denies until you fix it.
- An adapter can only be registered for a type the same `ScopesPlugin` call lists.
- Filters narrow queries. A filter is an `AssignmentScopeFilter` or a closure typed
  `function (Builder $query, AssignmentScopeRuntime $runtime)`; read `$runtime->user`, `->role`, `->grant`, `->actor`,
  `->panel`, `->scope`, `->now`, `->phase` and `->proposed`. A closure with other parameters fails the compile.
- A scope type declared by several layers must be declared identically; contributions that differ in filter, label or
  directory conflict (they are not merged).

## Contracts you can implement

| Contract | Namespace | Use |
|---|---|---|
| `AssignmentScopeFilter` | `AzGuard\Scopes\Contracts` | Narrow the candidate query of a scope |
| `AssignmentScopeAccessAdapter` | `AzGuard\Scopes\Contracts` | Decide access to a scope that is not a plain model |
| `AssignmentScopeMembership` | `AzGuard\Scopes\Contracts` | Whether a subject belongs to a scope |
| `ConfigurableAssignmentScopeDefinition` | `AzGuard\Scopes\Contracts` | A definition with settings |
| `AssignmentScopeEvaluator`, `AssignmentScopeDirectory`, `DescribesAssignmentScope` | `AzGuard\Contracts\Scopes` | Core contracts, if you replace the module's evaluator, directory or description |

Base classes: `BaseAssignmentScope` and `ModelAssignmentScopeDefinition`. A model that maps itself to a scope uses the
`ContextAware` trait and implements `MapsAssignmentScope`.

## Contract tests

`AzGuard\Scopes\Testing\AssignmentScopeFilterContractTests` is the contract suite for a filter you write. See
[Testing](/guides/testing).

## Without the plugin

A panel that declares no scopes is in mode `None`: a check without a scope uses tenant-wide grants, and a check in a
scope is denied (`context_not_accepted`). Rows stored with a scope while the plugin was attached stay in the
database but are inert, and nothing reads them as tenant-wide. Reattach the plugin and they work again.
