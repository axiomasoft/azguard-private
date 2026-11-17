# axiomasoft/azguard-audit

Transactional change journal and log retention pruning for `axiomasoft/azguard` panels.

Provides `AuditPlugin`, journal migrations (`azguard-audit-migrations`), named-storage migration generator
(`azguard:audit:migration`), retention pruning (`azguard:audit:prune`), and Packman `SchemaSetProvider` metadata.

```php
use AzGuard\Audit\AuditPlugin;

$panel->plugins([
    AuditPlugin::make(retentionDays: 90),
]);
```

MIT license.
