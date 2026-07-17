# Sharing Permissions with Inertia

When building an Inertia.js SPA with Vue or React, you need to share a curated set of the current user's permissions with the frontend to show/hide UI elements.

## Two tiers of permission sharing

AzGuard supports two complementary patterns:

1. **Per-page abilities** (resource-specific) — use the `AbilitiesDto` for detailed resource abilities; documented in [Frontend Abilities](../basic-usage/abilities-frontend.md).
2. **App-wide permission subset** (coarse controls) — share a curated list of keys via `AzGuard::abilitiesFor()` in the Inertia middleware for navigation/app shell.

This guide covers the second tier.

## Shared app permissions via `abilitiesFor()`

The `abilitiesFor()` API projects only the requested permissions, never the full catalog:

```php
use AzGuard\Facades\AzGuard;

$permissions = AzGuard::abilitiesFor(
    user: $request->user(),
    panelId: 'app',
    keys: [
        'app.documents.create',
        'app.documents.delete',
        'app.invoices.export',
        'app.users.manage',
    ],
);

// Returns only: ['app.documents.create' => true, 'app.documents.delete' => false, ...]
```

### Setup via `HandleInertiaRequests`

Share the curated permission set in your middleware:

```php
// app/Http/Middleware/HandleInertiaRequests.php
namespace App\Http\Middleware;

use AzGuard\Facades\AzGuard;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user() ? AzGuard::abilitiesFor(
                    user: $request->user(),
                    panelId: 'app',
                    keys: [
                        // Navigation / app shell controls
                        'app.documents.create',
                        'app.documents.delete',
                        'app.invoices.export',
                        'app.users.manage',
                    ],
                ) : [],
            ],
        ]);
    }
}
```

## Using in Vue 3

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()

// Type-safe with strict mode (see below for typing setup)
const canCreate = computed(
  () => page.props.auth?.permissions?.['app.documents.create'] ?? false
)
const canExport = computed(
  () => page.props.auth?.permissions?.['app.invoices.export'] ?? false
)
</script>

<template>
  <div class="navbar">
    <NavLink href="/documents">Documents</NavLink>
    <button v-if="canCreate" href="/documents/create">New Document</button>
    <button v-if="canExport" @click="exportReport">Export</button>
  </div>
</template>
```

### TypeScript permissions helper

For type safety, generate a permissions helper from your enum:

```typescript
// resources/js/lib/permissions.ts
export const AppPermissions = {
  'app.documents.create': 'app.documents.create',
  'app.documents.delete': 'app.documents.delete',
  'app.invoices.export': 'app.invoices.export',
  'app.users.manage': 'app.users.manage',
} as const

export type AppPermission = typeof AppPermissions[keyof typeof AppPermissions]

export type PermissionsMap = Record<AppPermission, boolean>
```

Then use it in your Middleware and pages:

```php
// app/Http/Middleware/HandleInertiaRequests.php
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        $keys = [
            'app.documents.create',
            'app.documents.delete',
            'app.invoices.export',
            'app.users.manage',
        ];

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user()
                    ? AzGuard::abilitiesFor(
                        user: $request->user(),
                        panelId: 'app',
                        keys: $keys,
                    )
                    : [],
            ],
        ]);
    }
}
```

```vue
<!-- resources/js/Pages/Dashboard.vue -->
<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { AppPermissions, type PermissionsMap } from '@/lib/permissions'

const page = usePage<{ auth: { permissions: PermissionsMap } }>()

const canCreate = computed(
  () => page.props.auth?.permissions?.[AppPermissions['app.documents.create']] ?? false
)
</script>
```

## Using in React

```tsx
import { usePage } from '@inertiajs/react'

export function AppShell() {
  const { auth } = usePage<{
    auth: {
      permissions: Record<string, boolean>
    }
  }>().props

  const canCreate = auth?.permissions?.['app.documents.create'] ?? false
  const canExport = auth?.permissions?.['app.invoices.export'] ?? false

  return (
    <nav>
      <a href="/documents">Documents</a>
      {canCreate && <button onClick={createDocument}>New</button>}
      {canExport && <button onClick={exportReport}>Export</button>}
    </nav>
  )
}
```

## Best practices

| Do | Don't |
|---|---|
| **Share a curated key list** — only the permissions the frontend UI needs | ❌ Dump the entire permission catalog to avoid "permission not shared" surprises |
| **Use `abilitiesFor()` for app-shell, nav, and shared layout checks** | ❌ Use it for detailed, per-resource logic (that's `AbilitiesDto`'s role) |
| **Define the key list once** and sync it across middleware and TypeScript (e.g., in a config or constant) | ❌ Hardcode keys in 5 places and wonder why nav shows the wrong things |
| **Validate all writes server-side** — never trust frontend permission state | ❌ Assume frontend permissions guarantee authorization |

## Important: frontend permissions are UX only

Frontend permission checks show/hide UI. **Every write action must be protected server-side** by `#[CheckPermission]` or `Gate::allows()`. A user can modify frontend state or disable JS. Permission checks on the server are the real security boundary.
