# axiomasoft/azguard-scopes

Scoped role and permission assignment filtering for `axiomasoft/azguard` panels.

Provides `ScopesPlugin`, queryable and external assignment scope adapters, role-binding eligibility filters,
membership requirements, and scope directory lookups on top of `axiomasoft/azguard` and `axiomasoft/packman`.

```php
use AzGuard\Scopes\ScopesPlugin;

$panel->plugins([
    ScopesPlugin::inherit(ProjectScope::class),
]);
```

MIT license.
