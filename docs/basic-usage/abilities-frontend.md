# Frontend Abilities

Abilities DTOs carry computed boolean flags from your policies to the front end. They answer: "what can this user do with *this specific resource right now*?"

## Why DTOs, not raw Gate calls on the front end?

Sending `abilities: { canEdit: true, canDelete: false }` in Inertia props is explicit, cacheable, and testable. The alternative — calling the API on every UI interaction — is slower and harder to reason about.

## Generating an Abilities DTO

AzGuard generates a typed Abilities DTO for each panel domain automatically:

```bash
php artisan make:guard-abilities App Documents
```

This creates:

```php
namespace App\Guards\App\Documents\Abilities;

use App\Guards\App\Documents\Permissions\DocumentsPermission;
use AzGuard\Abilities\AbilitiesDto;
use AzGuard\Facades\AzGuard;

final readonly class DocumentsAbilities extends AbilitiesDto
{
    public function __construct(
        public bool $viewAny,
        public bool $view,
        public bool $create,
        public bool $update,
        public bool $delete,
    ) {}

    protected static function abilityMap(): array
    {
        return [
            'viewAny' => AzGuard::permission(panelId: 'app', permission: DocumentsPermission::ViewAny),
            'view' => AzGuard::permission(panelId: 'app', permission: DocumentsPermission::View),
            'create' => AzGuard::permission(panelId: 'app', permission: DocumentsPermission::Create),
            'update' => AzGuard::permission(panelId: 'app', permission: DocumentsPermission::Update),
            'delete' => AzGuard::permission(panelId: 'app', permission: DocumentsPermission::Delete),
        ];
    }
}
```

## Instantiating via `make()`

The `make()` factory resolves all ability flags against the Gate automatically:

```php
use App\Guards\App\Documents\Abilities\DocumentsAbilities;
use App\Models\Document;

// Resolve against current user + Gate
$abilities = DocumentsAbilities::make($document);

// Convert to array for the frontend
$abilityFlags = $abilities->toArray();
// => ['viewAny' => true, 'view' => true, 'create' => false, ...]
```

The `make()` method:
1. Accepts any arguments your `abilityMap()` permissions check against (e.g., `$document`)
2. Resolves each ability by calling `Gate::allows(key, $document)`
3. Returns a DTO instance with all flags computed
4. **Never leaks non-boolean fields** — `toArray()` filters to boolean properties only

## Passing to Inertia

Pass abilities at the **page level**, not in global shared data. Abilities are resource-specific.

```php
use App\Guards\App\Documents\Abilities\DocumentsAbilities;
use Inertia\Inertia;
use Inertia\Response;

public function show(Document $document): Response
{
    return Inertia::render('Documents/Show', [
        'document'  => $document,
        'abilities' => DocumentsAbilities::make($document)->toArray(),
    ]);
}
```

## Consuming in Vue

```vue
<script setup lang="ts">
const props = defineProps<{
  document: {
    id: number
    title: string
  }
  abilities: {
    viewAny: boolean
    view: boolean
    create: boolean
    update: boolean
    delete: boolean
  }
}>()
</script>

<template>
  <div>
    <h1>{{ document.title }}</h1>
    <button v-if="abilities.update" @click="edit">Edit</button>
    <button v-if="abilities.delete" @click="destroy">Delete</button>
  </div>
</template>
```

## Consuming in React

```tsx
interface DocumentAbilities {
  viewAny: boolean
  view: boolean
  create: boolean
  update: boolean
  delete: boolean
}

interface DocumentPageProps {
  document: { id: number; title: string }
  abilities: DocumentAbilities
}

export function DocumentShow({ document, abilities }: DocumentPageProps) {
  return (
    <div>
      <h1>{document.title}</h1>
      {abilities.update && <button onClick={edit}>Edit</button>}
      {abilities.delete && <button onClick={destroy}>Delete</button>}
    </div>
  )
}
```

## Rules

- **DTO fields are constructor parameters** — they are resolved exactly once at instantiation via `make()`, then immutable.
- **Pass per-page, not globally** — abilities are resource/context-specific. Global shared props grow unbounded and leak unrelated permissions.
- **The DTO never duplicates checks** — all logic routes through your Gate / policies.
- **`toArray()` always returns boolean-only flags** — other properties are excluded for security and simplicity.
