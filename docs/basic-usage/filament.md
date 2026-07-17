# Filament Integration

The `axioma-studio/azguard-filament` package provides a first-party UI for
managing roles and direct grants, plus **config-driven, zero-boilerplate
authorization** for your own resources (Filament 5).

## Installation

```bash
composer require axioma-studio/azguard-filament
php artisan vendor:publish --tag=az-guard-filament-config
```

Register the plugin in your Filament panel provider, pointing it at the AzGuard
panel it should manage:

```php
use AzGuard\Filament\AzGuardPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        AzGuardPlugin::make()->forPanel('admin'),
    ]);
}
```

## How resource permissions work

You do **not** add authorization code to your resources. The plugin discovers
the panel's resources and pages and generates one permission per ability, keyed
`{panel}.{resource}.{ability}` — e.g. `admin.post.view_any`, `admin.post.create`.

Everything is controlled from `config/az-guard-filament.php`: the ability set,
the key scheme, the source, exclusions, and a `super_admin` bypass.

### Sources

Discovered keys are always registered in the catalog (so they appear in the
Role UI and can be granted). The source decides how access is *enforced* and
what is generated:

- **`database`** (default) — the runtime gate enforces; nothing is generated.
- **`enum`** — generate a typed permission enum per resource (still
  gate-enforced).
- **`policy`** — generate a Laravel policy per resource; Filament's native
  authorization enforces them and the runtime gate steps aside.

  ```bash
  php artisan guard:filament:generate --source=enum
  php artisan guard:filament:generate --source=policy
  ```

Preview without writing anything:

```bash
php artisan guard:filament:generate --dry-run
```

### Enforcement

With `enforce = true` (the default), the plugin makes Filament consult the Gate
for every resource and answers each check from the user's AzGuard permissions.
A user sees and can act on a resource only when they hold the matching
permission — with no base class, trait, or policy in the resource. A role
carrying the `*` wildcard (e.g. a SuperAdmin role) passes every check.

To opt out and manage authorization yourself, set `enforce` to `false`.

### Pages and widgets — enforce, don't just hide

Resource CRUD (above) is enforced through the Gate. Custom **Pages** and
**Widgets** are not: Filament routes them through their own static
`canAccess()` / `canView()` checks, which never touch the Gate, so the runtime
gate structurally cannot see them. AzGuard still catalogues a
`{panel}.{page}.view` / `{panel}.{widget}.view` permission for every
discovered page/widget so it appears in the Role UI — but on a bare custom
page or widget, that permission is a catalog entry only. Overriding
`shouldRegisterNavigation()` to check it only hides the nav link; the page
stays reachable at its URL and the widget's markup (and any data it queries)
stays reachable on any page it's placed on. **Nav-hiding is not access
control.**

Add the matching trait to any custom page or widget you want the catalogued
permission to actually enforce:

```php
use AzGuard\Filament\Concerns\HasAzGuardPage;
use Filament\Pages\Page;

class Settings extends Page
{
    use HasAzGuardPage;
}
```

```php
use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Filament\Widgets\Widget;

class RevenueChart extends Widget
{
    use HasAzGuardWidget;
}
```

`HasAzGuardPage` overrides `canAccess()`; `HasAzGuardWidget` overrides
`canView()`. Both consult the same `{panel}.{page|widget}.view` permission the
catalog already advertises, resolved against the AzGuard panel linked via
`AzGuardPlugin::forPanel()`. Because Filament calls `canAccess()` on every
mount *and* every Livewire round-trip (not only when rendering the nav link),
this closes the URL-reachability gap, not just the sidebar. Opt in per class —
this is not automatic, mirroring how resources need `enforce = true` but pages
and widgets need the trait.

## Built-in management resources

### RoleResource

Lists all roles (PHP class roles + custom DB roles) for the configured panel.
You can view class roles read-only, create/edit/delete DB roles, and assign
permissions to DB roles from a picker grouped by permission group.

### DirectGrantResource

Lists direct grants for any user on the panel — create (user + permission +
optional expiry) and revoke.

### Doctor page

The **AzGuard Doctor** page is the GUI equivalent of `php artisan guard:doctor`:
it surfaces catalog conflicts, roles referencing unknown permissions, and the
panel → ability → handler map. The navigation badge turns red on errors and
yellow on warnings.

## Configuration

See [`config/az-guard-filament.php`](https://github.com/axioma-studio/azguard)
for the full, commented options: `panel`, `source`, `abilities`, `pages`,
`widgets`, `key`, `case`, `exclude`, `super_admin`, `enforce`, and generation
paths.

## Compatibility

Requires Filament `^5.0`.

## Invariant

The plugin only authorizes against permissions scoped to the panel passed to
`forPanel()`. App-panel roles have no effect inside the Filament admin.
