# Headless Quick Start

A minimal path for an embedded/headless consumer — a bridge, a library, a
service without an admin UI — that only needs `$user->can()` / a curated
frontend abilities list, no Filament chapters.

::: tip Fail-closed, not panel-less
AzGuard has no "no panel" runtime mode — every check is still resolved
through a registered panel (D14: YAGNI, fail-closed stays intact). This
guide shows the **minimal** complete setup, not a shortcut around panels.
`guard:doctor` prints an onboarding hint when 0 panels are registered, so an
empty setup is never silently mistaken for a broken one.
:::

## 1. Install

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

## 2. Implement `AzGuardUser`

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardUser;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

## 3. One minimal panel

A single panel is enough — you don't need a Filament resource or a UI to
back it.

```php
// app/Guards/Api/ApiGuardPanelProvider.php
namespace App\Guards\Api;

use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\Panel;
use App\Guards\Api\Permissions\ApiPermission;

class ApiGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api')
            ->path('api')
            ->permissionEnums([
                ApiPermission::class,
            ]);
    }
}
```

```php
// config/az-guard.php
'panels' => [
    \App\Guards\Api\ApiGuardPanelProvider::class,
],
```

```php
// app/Guards/Api/Permissions/ApiPermission.php
namespace App\Guards\Api\Permissions;

enum ApiPermission: string
{
    case ReadDocuments  = 'documents.read';
    case WriteDocuments = 'documents.write';
}
```

## 4. Grant and check — no Filament, no controller

```php
// Direct grant — no role class, no roles table entry needed
AzGuard::forUser($user)->on('api')->grant(ApiPermission::ReadDocuments);

// Check — plain PHP, works in a job, a console command, a queue listener
$user->hasPermission(ApiPermission::ReadDocuments); // true

// Curated boolean projection for a non-Filament frontend/API response
AzGuard::abilitiesFor(
    user: $user,
    panelId: 'api',
    keys: [
        ApiPermission::ReadDocuments->value,
        ApiPermission::WriteDocuments->value,
    ],
);
```

See [Direct Grants](/basic-usage/direct-grants) for the full fluent grant
grammar and [Frontend Abilities](/basic-usage/abilities-frontend) for the
`abilitiesFor()` contract.

## 5. Verify

```bash
php artisan guard:doctor
```

`guard:doctor` works without Filament — it inspects registered panels,
enums, and roles. On a fresh install with 0 panels it prints an onboarding
hint pointing back to this page instead of silently passing.

## Next steps

- [Panels](/advanced/panels) — panel isolation model
- [Direct Grants](/basic-usage/direct-grants) — full grant grammar
- [Frontend Abilities](/basic-usage/abilities-frontend) — curated boolean projections
- [Quick Start](/introduction/quick-start) — the full path, including roles and Filament
