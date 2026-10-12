# Hooks, restrictions, change pipes and plugins

Extension points of a panel, in decision order:

```
before hooks → sources → super admin → policy → authority → restrictions → grant conditions → after hooks
```

| Extension point | Can | Cannot |
|---|---|---|
| **Before hook** | Deny early (`BeforeResult::Deny`) | Grant. Unlike `Gate::before()`, a hook never allows |
| **Restriction** | Deny a candidate (`RestrictionResult::deny('reason')`) | Grant |
| **Grant condition** | Drop one grant before grants are combined with OR | Grant |
| **After hook** | Observe the final `Decision` | Change it |
| **Change pipe** | Inspect, adjust or cancel a grant/revoke change | Bypass validation |
| **Plugin** | Attach all of the above with one call | — |

Hooks that throw do not break the request. They **deny** with `hook_error`, `restriction_error` or
`condition_error`, and the error is logged with its code.

`azguard:explain` shows which component decided.

## Before hooks

An object or a class that implements `BeforeHook`, or a closure with the signature of `BeforeHook::before()`: exactly
the parameters `AccessRequest $request, EvaluationContext $context`, each declared with its type, and the return type
`BeforeResult`. The arguments are passed positionally. A closure with another signature fails when the panel is
compiled, with a `DefinitionException` that names the slot and the mismatch, not at the first request.

```php
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

->before(fn (AccessRequest $request, EvaluationContext $context): BeforeResult => $context->subjectModel()?->banned_at !== null
    ? BeforeResult::Deny
    : BeforeResult::Continue)
```

## Restrictions

`azguard:make:restriction BusinessHours --panel=admin` creates a skeleton. Register it with
`->restrictions([BusinessHoursRestriction::class])`.

```php
final class BusinessHoursRestriction implements Restriction
{
    public function key(): string
    {
        return 'business-hours';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return str_ends_with($request->permission()->local(), '.delete');
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        $hour = (int) $context->now()->format('G');   // the context clock: Carbon::setTestNow() works

        return $hour >= 9 && $hour < 18 ? RestrictionResult::pass() : RestrictionResult::deny('outside_business_hours');
    }

    public function exemptsSuperAdmin(): bool
    {
        return true;   // false restricts the super admin too
    }
}
```

On resource lists (Filament, `ContextAware` queries), a restriction must also implement `FiltersAccessQueries`.
It is then compiled to SQL, and `appliesTo()` is not called. See
[Filament → lists](/guides/filament#_4-lists-need-query-capable-rules).

## After hooks

An object or a class that implements `AfterHook`, or a closure with the signature of `AfterHook::after()`:
`AccessRequest $request, EvaluationContext $context, Decision $decision` and the return type `void`.

```php
->after(function (AccessRequest $request, EvaluationContext $context, Decision $decision): void {
    if (! $decision->allowed()) {
        Metrics::increment('azguard.denied', ['reason' => $decision->reason->value]);
    }
})
```

For an audit trail of decisions, prefer `trace_decisions` and the `AccessDecided` event. For an audit trail of
changes, use the [events](/reference/events) or the built-in `AuditPlugin`.

## Change pipes

Every grant, revoke, update and dynamic-permission change passes through the panel's pipes inside the change
transaction. A pipe implements `ChangePipe` (or is its class name, or a closure `Closure(Change, Closure): ChangeResult`
with typed parameters and return type) and works like Laravel middleware: it must call `$next` once and return its
result. `$change->cancel('reason')` throws `ChangeCancelledException`, and nothing is written.

```php
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeType;
use AzGuard\Contracts\Changes\ChangePipe;

final class TimeBoxedSuperAdmin implements ChangePipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        if ($change->type === ChangeType::GrantRole && $change->role?->key() === 'superadmin' && $change->until === null) {
            $change->cancel('superadmin is granted for a limited time only: pass until.');
        }

        return $next($change);
    }
}

->changing([TimeBoxedSuperAdmin::class])
```

`$change->actor` is the actor. A change from the console without `AzGuard::actingAs()` has the system actor
with the reason `console`.

## Plugins

A plugin bundles sources, roles, restrictions and pipes. It implements `Plugin`: an `id()` and a `register()` that only
declares through the panel builder (no I/O, no current user, tenant, request or clock, no global listener). There is no
`boot()` and no base class. Its settings are typed parameters of its own factory. Each panel gets its own copy.

```php
final class CompliancePlugin implements Plugin
{
    private function __construct(private readonly bool $timeBoxSuperAdmin) {}

    public static function make(bool $timeBoxSuperAdmin = true): self
    {
        return new self($timeBoxSuperAdmin);
    }

    public function id(): string
    {
        return 'app/compliance';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->restrictions([BusinessHoursRestriction::class]);

        if ($this->timeBoxSuperAdmin) {
            $panel->changing([TimeBoxedSuperAdmin::class]);
        }
    }
}

->plugins([CompliancePlugin::make()])
```

- **Configuration.** A plugin's settings sit below the provider in the
  [settings order](/reference/configuration#where-a-panel-setting-comes-from).
- **Opting out.** `withoutPlugins(['app/compliance'])` removes a plugin, for example one added by
  `configurePanels()`.
- **Ids.** An id is `name` or `vendor/name`; the prefix `azguard/` is reserved for the plugins of this package, and a
  plugin class outside the namespace `AzGuard\` that takes it fails with `PluginConflictException`.
- **Requirements.** A plugin does not require another plugin. What a contribution needs is implied by its slot and
  checked by core.
- **Testing.** Package authors should use `PluginContractTests`, `RestrictionContractTests` and
  `HookContractTests` from `AzGuard\Testing\Contracts`.

### Audit plugin

`AuditPlugin::make(retentionDays: 365)` writes one row per effective change to `azg_audit_log`, created by the standard migrations, in the same transaction as the change.
`azguard:audit:prune` deletes rows older than the retention.
