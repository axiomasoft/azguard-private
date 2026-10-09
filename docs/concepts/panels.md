# Panels

A **panel** is one area of your application with its own access rules: an admin back office, a customer
cabinet, a seller portal, an API. Each panel defines:

- who can be a subject (`for(User::class)`);
- its permissions, roles and policies (discovered from its directory);
- where grants come from (`permissions([...sources])`);
- tenant and scope rules, hooks, cache and Gate settings.

Grants never leak between panels. An `editor` role in `admin` says nothing about `cabinet`.

## A panel is a directory

```
app/Guards/
├── Admin/
│   ├── AdminGuardPanelProvider.php   # the panel definition
│   ├── Permissions/Posts/PostPermission.php
│   ├── Policies/Posts/PostPolicy.php
│   ├── Roles/EditorRole.php
│   └── Abilities/Posts/PostAbilities.php
├── Cabinet/
│   └── CabinetGuardPanelProvider.php
└── Shared/Sources/LdapSource.php     # #[AsSource] classes every panel may name
```

The built-in `FolderSource` reads the directories next to the provider. The names come from
`azguard.discovery`, and every panel has this source. Create a panel with:

```bash
php artisan azguard:make:panel Cabinet --model='App\Models\Customer'
```

The generator also lists the provider in `config/azguard.php` → `panels.providers`. Providers of packages and
modules can register themselves with `AzGuard::registerPanel(Provider::class)` from a service provider.

## The provider

```php
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;

final class AdminGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';                       // stable id: [a-z0-9][a-z0-9-]{0,63}
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->label('Back office')
            ->for(User::class, guard: 'web')  // subject models and the Laravel auth guard
            ->default()                       // the default panel for User (see "Which panel?")
            ->middleware(['web', 'auth'])     // run when a request enters the panel
            ->permissions([DatabaseSource::make()])
            ->roles([SuperAdminRole::class]); // roles from outside the panel directory
    }
}
```

`PanelProvider` extends Laravel's `ServiceProvider`. The panel is compiled once at boot and is immutable
afterwards. Changing it later throws `RegistryFrozenException`.

### Builder methods

| Method | Purpose |
|---|---|
| `label()`, `description()`, `presentation([...])` | Text and UI hints |
| `for($models, guard:, directory:)` | Subject models; `guard` is the Laravel auth guard, not a panel id |
| `default()` | Default panel for its subject models |
| `resourcePrefix(true\|'custom'\|false)` | Prefix of permission names (default: the panel id) |
| `permissions([...])` | Extra enum classes, source objects and named sources |
| `roles([...])`, `policies([...])`, `discover($path, $namespace)` | Definitions from outside the panel directory |
| `middleware([...])`, `entry($permission)`, `onDenied($response)`, `requireRouteChecks()` | Entering the panel over HTTP ([Checking access](/guides/checking-access#routes)) |
| `tenants()`, `scopes()`, `tenantResolvers()`, `scopeResolvers()`, `resourceScopes()` | [Tenants and scopes](/guides/tenants-and-scopes) |
| `before()`, `restrictions()`, `after()`, `changing()`, `grantConditions()`, `plugins()`, `withoutPlugins()`, `fields()` | [Hooks and plugins](/advanced/hooks-and-plugins) |
| `gate(GateMode)`, `cache()`, `consistency()` | [Performance and consistency](/advanced/performance) |
| `doctorChecks([...])` | Extra `azguard:doctor` checks |

### Settings for all panels

Settings resolve in this order: the provider, then its plugins, then `AzGuard::configurePanels()`, then
`config('azguard.defaults')`. `php artisan azguard:panels:list --settings` shows where each value came from.

```php
// AppServiceProvider::register()
AzGuard::configurePanels(fn (PanelBuilder $panel) => $panel->roles([SuperAdminRole::class]));
AzGuard::configurePanel('admin', fn (PanelBuilder $panel) => $panel->cache(store: 'redis'));
```

## Permission names

A permission has three equivalent spellings. The enum case is the preferred one:

| Form | Example | Notes |
|---|---|---|
| Enum case | `PostPermission::Update` | Type-safe; the panel follows from the enum |
| Prefixed name | `'admin.posts.update'` | What `can()`, `@can` and the middleware usually receive |
| Full name | `'admin:posts.update'` | Unambiguous; does not change if `resourcePrefix` changes |
| Local name | `'posts.update'` | Resolved in the current or default panel |

`resourcePrefix('backoffice')` changes only the prefixed form (`backoffice.posts.update`). Enum cases and
full names keep working.

## Which panel?

Every check runs in exactly one panel. The engine picks it like this:

1. **Explicit signals.** A `guard:` argument, `$user->guard('admin')`, a full or prefixed name, or an enum
   registered in one panel. All signals must agree. Otherwise the call throws `ConflictingPanelException`.
2. **Otherwise, the current panel of the request**, which `azguard.panel:{id}` or the Filament plugin sets.
3. **Otherwise, the model's panel.** That is `azguardDefaultPanel()` on the model, the panel marked
   `default()`, or the only panel that accepts the model.
4. **Otherwise**, `PanelNotResolvedException`.

```php
$user->hasPermission(PostPermission::View);                // the enum names the panel
$user->hasPermission('posts.view', guard: 'admin');        // explicit panel id
$user->guard('admin')->hasRole('editor');                  // a SubjectAccess bound to one panel
$user->azguard()->panels();                                // ['admin', ...]: panels that accept the model
```

`guard` in a check is a **panel id**. In `for(model:, guard:)` it is a Laravel auth guard. The trait's
`guard()` also keeps Eloquent's mass-assignment meaning when it gets an array.

## Several panels for one model

One `User` can be a subject of `admin` and `cabinet`. Mark one panel `default()` or always name the panel.
Grants, caches and state versions are separate per panel.
