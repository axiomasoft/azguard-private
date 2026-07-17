# Policy Integration

AzGuard and Laravel Policies are complementary. This recipe shows how to combine them cleanly.

## When to use which

| Scenario | Use |
|---|---|
| Can this user edit documents at all? | AzGuard permission |
| Can this user edit *this specific* document? | Laravel Policy |
| Can this user delete their *own* documents? | Both: permission + policy |

## Example: Document policy

```php
// app/Policies/DocumentPolicy.php
namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

class DocumentPolicy
{
    // Global view check — do any documents exist for this user?
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(DocumentsPermission::View);
    }

    // Object-level: can the user see this specific document?
    public function view(User $user, Document $document): bool
    {
        return $user->hasPermission(DocumentsPermission::View)
            && ($document->is_public || $document->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(DocumentsPermission::Create);
    }

    public function update(User $user, Document $document): bool
    {
        if (! $user->hasPermission(DocumentsPermission::Edit)) {
            return false;
        }
        // Only own documents, unless manager+
        return $document->user_id === $user->id
            || $user->hasRole('manager');
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->hasPermission(DocumentsPermission::Delete);
    }
}
```

## Registering the policy

```php
// app/Providers/AppServiceProvider.php
use App\Models\Document;
use App\Policies\DocumentPolicy;

protected $policies = [
    Document::class => DocumentPolicy::class,
];
```

## Using with `#[CheckPermission]`

When `arguments: ['document']` is passed, AzGuard hands the model to Gate:

```php
class DocumentController extends Controller
{
    #[CheckPermission(permission: DocumentsPermission::Edit, arguments: ['document'])]
    public function update(UpdateDocumentRequest $request, Document $document): Response
    {
        // Reaches here only if:
        // 1. $user->hasPermission(DocumentsPermission::Edit) = true (AzGuard)
        // 2. DocumentPolicy::update($user, $document) = true (Policy)
        $document->update($request->validated());
        return back();
    }
}
```

AzGuard's Gate integration ensures both the permission check and the policy run in the correct order.

## Testing policies with AzGuard

```php
public function test_user_cannot_edit_others_document(): void
{
    $owner  = User::factory()->create();
    $editor = User::factory()->create();

    $editor->assignRole('editor');  // has DocumentsPermission::Edit

    $document = Document::factory()->for($owner)->create();

    $this->actingAs($editor)
        ->patch(route('documents.update', $document), ['title' => 'New'])
        ->assertForbidden();  // Policy denies: not owner, not manager
}

public function test_manager_can_edit_any_document(): void
{
    $manager  = User::factory()->create();
    $manager->assignRole('manager');

    $document = Document::factory()->create();

    $this->actingAs($manager)
        ->patch(route('documents.update', $document), ['title' => 'New'])
        ->assertRedirect();  // Policy allows: hasRole('manager')
}
```
