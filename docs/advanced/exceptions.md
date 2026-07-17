# Exceptions

AzGuard uses standard Laravel exceptions for authorization failures and throws its own specific exceptions for configuration problems.

## Authorization exceptions

When a permission check fails at the Gate level, Laravel throws `Illuminate\Auth\Access\AuthorizationException`. AzGuard does not throw its own authorization exception — it relies on the standard Laravel mechanism.

```php
// These all throw AuthorizationException on failure:
$this->authorize('app.documents.delete', $document);
Gate::authorize('app.documents.delete', $document);

// These return a boolean — no exception:
$user->hasPermission(DocumentsPermission::Delete);  // false
$user->checkPermission(DocumentsPermission::Delete); // false (silent)
Gate::allows('app.documents.delete');               // false
```

### Silent mode

Use `checkPermission()` instead of `hasPermission()` when you want `false` rather than an exception on missing context or unresolvable user:

```php
// hasPermission() may throw if the user is not authenticated or panel is unresolved
// checkPermission() always returns bool
$allowed = $user->checkPermission(DocumentsPermission::View);
```

## Configuration exceptions

These exceptions are thrown during boot (or first request) if the configuration is invalid:

| Exception | When thrown |
|---|---|
| `AzGuard\Exceptions\PanelNotFoundException` | A panel id is referenced that is not registered in `config/az-guard.php` |
| `AzGuard\Exceptions\PanelNotSetException` | A permission check runs with no resolvable current panel |
| `AzGuard\Exceptions\InvalidMorphTypeException` | `az-guard.column_names.morph_type` holds an unsupported value (not `int`/`ulid`/`uuid`) |
| `AzGuard\Registry\Exceptions\InvalidPermissionKeyException` | A permission key cannot be resolved or is malformed |

Every one of these extends `AzGuard\Exceptions\AzGuardException`, so a single catch handles any AzGuard domain error regardless of sub-namespace:

```php
use AzGuard\Exceptions\AzGuardException;

try {
    AzGuard::permission('reports', ReportPermission::View);
} catch (AzGuardException $e) {
    // Catches PanelNotFoundException, PanelNotSetException,
    // InvalidMorphTypeException and InvalidPermissionKeyException alike.
    report($e);
}
```

## Handling authorization failures

### Returning JSON for APIs

```php
// app/Exceptions/Handler.php
use Illuminate\Auth\Access\AuthorizationException;

public function render($request, Throwable $e)
{
    if ($e instanceof AuthorizationException && $request->expectsJson()) {
        return response()->json([
            'message' => 'This action is unauthorized.',
            'error'   => 'forbidden',
        ], 403);
    }

    return parent::render($request, $e);
}
```

### Custom denied response

To customize the denied message globally:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Auth\Access\Response;

public function boot(): void
{
    Gate::after(function ($user, $ability, $result) {
        if ($result === false) {
            return Response::deny(
                'You do not have permission: ' . $ability,
                403
            );
        }
    });
}
```

### Redirect unauthenticated users

The default Laravel behaviour redirects unauthenticated users to the login page. AzGuard does not override this — it relies on your `auth` middleware being in place before the AzGuard middleware stack.

## Exception debugging

Run `php artisan guard:doctor` — it reports configuration problems before they reach production. In CI, add the doctor command as a check:

```yaml
# .github/workflows/ci.yml
- name: AzGuard doctor check
  run: php artisan guard:doctor
```

The command exits with a non-zero code if any errors are found, failing the pipeline.
