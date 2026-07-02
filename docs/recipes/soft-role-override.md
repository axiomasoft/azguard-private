# Recipe: Soft Role Override

Sometimes a user needs elevated access for a limited time without being permanently assigned a higher role. A soft override keeps the audit trail clean — the base role is unchanged; the override is a direct grant with an expiry.

## Pattern

```php
use App\Guards\App\Documents\Permissions\DocumentsPermission;

// Grant publish access until end of sprint (pass an explicit expiry)
$user->grant(DocumentsPermission::Publish, 'app', now()->addDays(14));
```

AzGuard merges direct grants with role permissions at resolution time. When the grant expires, access reverts to the user's base role automatically — no cleanup job required.

## Checking the override

```php
$user->hasPermission(DocumentsPermission::Publish); // true while grant is active
                                                       // false after expires_at
```

## Auditing overrides

```bash
# List a user's active direct grants for a panel
php artisan guard:grants --user=42 --panel=app
```

Shows the user's active direct grants including their expiry. Use
`php artisan guard:list-permissions app` to see all declared permissions for a panel.

::: tip
For bulk temporary elevation, consider creating a DB-backed custom role with a named purpose and a scheduled job to remove users from it after the event.
:::
