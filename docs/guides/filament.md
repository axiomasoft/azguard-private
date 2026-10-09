# Filament

`axiomasoft/azguard-filament` connects a Filament 5 panel to an AzGuard panel:

- resources, pages and widgets are authorized by AzGuard permissions;
- resource lists show only the records the user may view;
- you get read-only role pages and editors for grants and dynamic permissions.

```bash
composer require axiomasoft/azguard-filament
```

A Filament panel and an AzGuard panel are different things. The plugin names the AzGuard panel (the "guard
panel") that holds the permissions of a Filament panel.

## 1. Attach the plugin

```php
use AzGuard\Filament\AzGuardPlugin;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        // ->tenant(Team::class)   // a tenant must be set before the plugin
        ->plugin(
            AzGuardPlugin::make()
                ->guardPanel('admin')
                ->exclude(pages: [Dashboard::class], widgets: [AccountWidget::class, FilamentInfoWidget::class]),
        );
}
```

On every request, including Livewire updates, the plugin enters the guard panel the way `azguard.panel` does.
Only users with a role of the guard panel get in. Filament's own `FilamentUser::canAccessPanel()` still applies
outside the `local` environment.

The plugin **enforces** by default (`enforce(true)`): every resource, page and widget must be decided by
AzGuard or excluded. A fresh Filament installation fails at boot with:

```
Filament\Pages\Dashboard in the Filament panel "admin", which enforces its permissions, is not decided by
AzGuard: use AzGuard\Filament\Concerns\AuthorizesPage and keep its canAccess(), or exclude the class.
```

Add the trait or exclude the class, as above.

## 2. Authorize resources, pages and widgets

```php
use AzGuard\Filament\Concerns\AuthorizesResource;

final class PostResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Post::class;
    protected static ?string $slug = 'posts';
}
```

| Trait | Permission names |
|---|---|
| `AuthorizesResource` | `{slug}.{ability}`: `posts.view_any`, `posts.view`, `posts.create`, `posts.update`, `posts.delete` and so on |
| `AuthorizesRelationManager` | Delegates to the related resource |
| `AuthorizesPage` | `pages.{slug}` |
| `AuthorizesWidget` | `widgets.{name}` |

- **Checks.** Every `can*()`, `authorize*()` and `get*AuthorizationResponse()` goes through AzGuard.
- **Queries.** The resource query is filtered to the records the user may view (`{slug}.view`). Lists,
  counts, global search and record routes see no other rows.
- **Your own query changes** belong in `modifyEloquentQuery()`, not in `getEloquentQuery()`.

## 3. Define the permissions

The permissions must exist in the guard panel. Generate enums for everything the Filament panel checks:

```bash
php artisan azguard:filament:generate              # resources, pages, widgets and the package editors
php artisan azguard:filament:generate --only=resources --with-policy
```

The command skips enums that already exist, such as `PostPermission` from `azguard:make:permission`, unless
you pass `--force`. Each generated enum carries `#[ForFilament(Class)]`, so `azguard:doctor` notices when the
Filament class is gone.

You can also read definitions from the Filament classes directly instead of enums:

```php
AzGuardPlugin::make()->guardPanel('admin')->definitions(FilamentDefinitions::Resources)->authority('grants');
// and in the guard panel provider:
->permissions([DatabaseSource::make(), FilamentSource::make('admin')])
```

## 4. Lists need query-capable rules

Filtering a list means turning every rule that decides `{slug}.view` into SQL. The scalar check
(`can('view', $post)`) can run any PHP. The list query cannot. So the list page throws
`VisibilityNotSupportedException` when something that decides `view` cannot filter a query:

| Message | Cause | Fix |
|---|---|---|
| `missing_exact_adapter (…PostPolicy)` | A policy method `#[Decides(PostPermission::View)]` | Remove the `view`/`viewAny` methods if they only return `true`, or implement `FiltersAccessQueries` on the policy |
| `resource_context_mapping` | The panel has assignment scopes, and the model does not say which scope a row belongs to | Implement `ProvidesAssignmentScope` with the `ContextAware` trait on the model; `resourceScopes()` cannot filter queries |
| `unsupported_selection (…FolderSource)` | The panel has a `GrantedAutomatically` role | Automatic roles cannot be turned into SQL; keep them out of panels with Filament lists |

A model that maps itself to a scope:

```php
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Scopes\ContextAware;

class Post extends Model implements ProvidesAssignmentScope
{
    use ContextAware;

    public function azguardContextType(): string
    {
        return 'team';
    }

    public function azguardContextRelation(): ?string
    {
        return 'team';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
```

A policy that also filters queries:

```php
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;

final class PostPolicy implements FiltersAccessQueries
{
    #[Decides(PostPermission::View)]
    public function view(Model $user, Post $post): bool
    {
        return ! $post->is_secret;
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context,
        Grant|RoleContribution|null $contribution = null): AccessPredicate
    {
        $allow = $request->permission()->local() === 'posts.view'
            ? AccessPredicate::eq('is_secret', false)
            : AccessPredicate::pass();

        // allow / deny / abstain: these must be disjoint and cover every row
        return AccessPredicate::partition($allow, AccessPredicate::not($allow), AccessPredicate::deny());
    }
}
```

The predicate must be equivalent to the PHP method. Return `AccessPredicate::unsupported()` when it cannot be.

## 5. Editors

By default the plugin adds these resources and pages. They are scoped to the panels named by `manages([...])`,
or to every writable panel.

| Editor | Permissions |
|---|---|
| Roles (read-only catalog) | `azguard-roles.view_any`, `azguard-roles.view` |
| Role grants | `azguard-role-grants.*` |
| Permission grants | `azguard-permission-grants.*` |
| Dynamic permissions | `azguard-permissions.*` |
| Panels page, Doctor page | `pages.azguard-panels`, `pages.azguard-doctor` |

```php
AzGuardPlugin::make()
    ->guardPanel('admin')
    ->manages(['admin', 'cabinet'])
    ->resources(permissions: false, doctor: false)   // turn off individual editors
    ->formExtensions(DepartmentField::class);        // custom components for grant fields
```

Defaults for every plugin instance are in `config/azguard-filament.php`:

- `guard_panel` and `manages`;
- `enforce`, `definitions` and `authority`;
- `abilities` and `exclude`.

Publish it with `php artisan vendor:publish --tag=azguard-filament-config`.

## Testing

```php
$this->actingAsWithRoles($editor, [EditorRole::class]);
AzGuard::actingAs('test', fn () => $editor->grantPermission(PostPermission::ViewAny));

$this->get('/admin/posts')->assertOk();
```
