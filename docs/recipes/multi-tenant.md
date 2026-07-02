# Multi-Tenant Roles

This recipe shows how to use AzGuard in a multi-tenant application where each tenant has its own set of custom roles.

## Approach

Use **static roles** for the base role set (every tenant gets them), and **dynamic roles** for custom tenant-specific roles created through the admin UI.

## Setup

```php
// app/Guards/App/Roles/ViewerRole.php — base role, same for all tenants
use AzGuard\Roles\BaseRole;

class ViewerRole extends BaseRole
{
    public function getName(): string { return 'viewer'; }
    public function getLevel(): int   { return 1; }

    public function permissions(): array
    {
        // Full, panel-prefixed permission keys
        return [
            'app.documents.view',
            'app.invoices.view',
        ];
    }
}
```

## Isolating by tenant

Use the **context** package to scope permissions to a tenant:

```php
use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;

// Middleware — set context on every request
public function handle(Request $request, Closure $next): Response
{
    $tenant = $request->user()?->tenant;

    if ($tenant) {
        app(AuthorizationContextManager::class)->set(
            new AuthorizationContext(
                panelId:     'app',
                contextType: 'tenant',
                contextId:   (string) $tenant->id,
            )
        );
    }

    return $next($request);
}
```

## Creating tenant-specific roles

```php
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;

// In a controller or job
public function createRole(CreateRoleRequest $request, Tenant $tenant): JsonResponse
{
    // Namespace the role name by tenant to keep DB roles distinct
    $role = Role::create([
        'name'  => "tenant-{$tenant->id}-{$request->name}",
        'level' => $request->level ?? 5,
    ]);

    foreach ($request->permissions as $key) {
        RolePermission::firstOrCreate([
            'role_id'        => $role->id,
            'permission_key' => $key,   // full key, e.g. 'app.documents.edit'
            'panel_id'       => 'app',
        ]);
    }

    return response()->json(['role' => $role], 201);
}
```

## Checking in context

```php
// One-off contextual check: hasPermissionIn(contextType, contextId, permission, panelId)
$user->hasPermissionIn('tenant', (string) $tenant->id, DocumentsPermission::View, 'app');
```

See [Context](/advanced/context) for the full context API.
