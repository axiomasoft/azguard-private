# Recipe: Temporary Access via Direct Grant

Direct grants let you give a user a single permission for a fixed period without modifying their role or creating a custom role.

## Use cases

- A contractor needs read access to a specific domain for two weeks
- A support engineer needs delete access while investigating a bug
- A reviewer needs publish access for a one-off release

## Creating the grant

```php
use AzGuard\Facades\AzGuard;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

// Fluent builder — ttl() takes seconds (two weeks)
AzGuard::forUser($contractor)
    ->on('app')
    ->ttl(14 * 24 * 3600)
    ->grant(DocumentsPermission::View);

// Or pass an explicit expiry to the model method:
$contractor->grant(DocumentsPermission::View, 'app', now()->addWeeks(2));
```

## Revoking early

```php
use AzGuard\Facades\AzGuard;

// Revoke a specific grant (deletes the row, flushes the user's cache)
AzGuard::revoke($contractor, DocumentsPermission::View, 'app');

// Or via the model / builder:
$contractor->revoke(DocumentsPermission::View, 'app');
AzGuard::forUser($contractor)->on('app')->revoke(DocumentsPermission::View);
```

## Via Filament

Open **Direct Grants → Create**, select the user and permission from the dropdowns, set an expiry date, and save. The grant is active immediately.

To revoke: find the grant in the list and click **Revoke**.

## Via artisan (dev / seeding)

```bash
# guard:grant {user-id} {permission} {panel} [--ttl=<seconds>]
php artisan guard:grant 42 app.documents.view app --ttl=1209600
```

## Cache invalidation

AzGuard automatically clears the user's permission cache when a grant is created or revoked. No manual cache flush needed.

::: tip
Grants are logged with `granted_by` and `reason`. Use these fields to maintain a meaningful audit trail for compliance.
:::
