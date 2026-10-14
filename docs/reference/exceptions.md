# Exceptions

Package exceptions extend `AzGuard\Exceptions\AzGuardException`, which is a `RuntimeException`. Each has a
stable machine code in `code()`. The code is part of the public contract; the message is not. The two
exceptions at the end of this page are plain `RuntimeException`s. Codes are also written to
the log when a failing component denies a check (see [Fail closed](/concepts/decisions#fail-closed)).

```php
try {
    $user->grantRole('editor', on: $team);
} catch (AssignmentScopeNotAcceptedException $e) {
    $e->code();   // 'context_not_accepted'
}
```

## Bases

| Exception | Code | When |
|---|---|---|
| `AzGuardException` | — | Abstract root of every package exception below (a `RuntimeException`) |
| `AuthorizationEngineException` | — | Abstract base of engine errors |
| `ChangeException` | — | Abstract base of change errors |
| `ConfigurationException` | — | Abstract base of configuration errors |
| `DefinitionException` | `definition` | A panel, source, role or plugin is declared in a way the package cannot build. |
| `DirectoryScanLimitException` | `directory_scan_limit` | A directory read its scan budget without filling the limit or proving the end of the candidates; a partial list is never returned as a complete one. |
| `InvalidIdentityException` | `invalid_identity` | A key, id or reference violates the identity grammar. |
| `PluginException` | — | Abstract base of plugin errors |
| `StorageException` | — | Abstract base of storage errors |

## Definition errors (boot or first use)

| Exception | Code | When |
|---|---|---|
| `AmbiguousPanelException` | `ambiguous_panel` | A permission enum belongs to several panels and the check names none of them. |
| `ConflictingPanelException` | `conflicting_panel` | Explicit panel signals of one check name different panels. |
| `DefaultPanelConflictException` | `default_panel_conflict` | Two panels are declared as the default panel of the same subject model. |
| `DuplicatePanelException` | `duplicate_panel` | A panel id is registered twice; an intended replacement uses `replace()`. |
| `DuplicatePermissionException` | `duplicate_permission` | Two sources of a panel contribute different definitions under one permission key. |
| `DuplicatePolicyBindingException` | `duplicate_policy_binding` | One permission is bound to two different policies. |
| `DuplicateRoleException` | `duplicate_role` | Two role classes of a panel share a key, or a former key of one role is a key of another. |
| `InvalidPolicyStructureException` | `invalid_policy_structure` | A policy-only permission has no policy binding, or a binding does not fit the permission. |
| `PanelNotResolvedException` | `panel_not_resolved` | The panel selection rule found no panel: nothing names one, the request has none and the model has no default. |
| `PrefixConflictException` | `prefix_conflict` | Two panels resolve to the same permission name prefix. |
| `RegistryFrozenException` | `registry_frozen` | The panel registry or a compiled panel builder is changed after the application has booted. |
| `SubjectNotAcceptedException` | `subject_not_accepted` | The model is not a subject of the panel. |
| `UnknownPanelException` | `unknown_panel` | No panel is registered under the id. |
| `UnknownSourceException` | `unknown_source` | A panel names a source that is not registered. |
| `WriterConflictException` | `writer_conflict` | A panel has more than one writer, that is, the database source twice. |

## Configuration

| Exception | Code | When |
|---|---|---|
| `InvalidConfigurationException` | `invalid_configuration[.check]` | The package configuration fails a load-time check. The code names the failed check after the dot (`invalid_configuration.enum`); a failure without a narrower check keeps the base code. |
| `MissingPermissionCheckException` | `missing_permission_check` | A route action of a panel in strict mode has no permission check and does not opt out of one. |

## Changes

| Exception | Code | When |
|---|---|---|
| `AssignmentScopeNotAcceptedException` | `context_not_accepted` | The assignment scope is not accepted for this role, target or tenant. |
| `AssignmentScopeRequiredException` | `context_required` | The role is granted only inside an assignment scope. |
| `ChangeCancelledException` | `change_cancelled` | A `changing` pipe cancelled the change; the whole operation is rolled back. |
| `InvalidChangeFieldsException` | `invalid_change_fields` | Grant fields fail validation; `errors()` maps each field to its messages. |
| `PanelNotWritableException` | `panel_not_writable` | The panel has no writer that the change pipeline can use. |
| `PermissionNotGrantableException` | `permission_not_grantable` | The permission is decided by its policy alone and never receives an exact assignment. |
| `RoleNotGrantableException` | `role_not_grantable` | The code role exists but is not granted through storage. |
| `StaleSelectionException` | `stale_selection` | The selected grant or build changed after it was read; the change is not applied. |
| `TenantMismatchException` | `tenant_mismatch` | The change addresses a tenant the panel or the assignment scope does not belong to. |
| `TenantRequiredException` | `tenant_required` | The panel requires a tenant for this change. |
| `UnknownPermissionException` | `unknown_permission` | The permission is not defined in any panel it could belong to. |
| `UnknownRoleException` | `unknown_role` | The role is neither a code role of the panel nor a key it can be granted under; a former key is not an alias. |

## Identity

| Exception | Code | When |
|---|---|---|
| `InvalidAssignmentScopeException` | `invalid_context` | An assignment scope reference is malformed. |
| `InvalidPanelIdException` | `invalid_panel_id` | A panel id does not match `[a-z0-9][a-z0-9-]{0,63}`. |
| `InvalidPermissionKeyException` | `invalid_permission_key` | A permission name or pattern is malformed, for example `*` alone. |
| `InvalidRoleKeyException` | `invalid_role_key` | A role key is malformed. |

## Plugins

| Exception | Code | When |
|---|---|---|
| `PluginConflictException` | `plugin_conflict` | Two plugins of one panel set the same setting to different values and the panel provider does not decide, or a plugin outside the package takes an id with the reserved `azguard/` prefix. |

## Storage

| Exception | Code | When |
|---|---|---|
| `StorageMismatchException` | `storage_mismatch` | The tables do not have the schema the storage expects; run the migrations. |
| `UnsupportedDirectWriteException` | `unsupported_direct_write` | Storage was written outside the change pipeline. |

## Engine

| Exception | Code | When |
|---|---|---|
| `ConsistencyException` | `consistency` | Values of one decision contradict each other: a reason the effect cannot carry, or different state tokens in one set. |
| `DecisionSetTooLargeException` | `decision_set_too_large` | A `decideMany()` names more distinct subjects than `decision_sets.max_subjects`. It is refused before any read; `subjects` and `limit` carry the counts. |
| `InvalidSourceContributionException` | `invalid_source_contribution` | A grant, role contribution or restriction result supplied by an extension breaks the decision contract. |

## Outside the hierarchy

| Exception | When |
|---|---|
| `DecisionFailedException` | `AzGuard\Laravel\Http`: the HTTP exception (503 or 500, generic message) that `DecisionResponder::authorize()` throws when authority could not be read or host code broke its contract. It carries no machine code and no internals; see [HTTP mapping](/reference/formats#http-mapping). |
| `RecursionDetectedException` | A check re-entered itself for the same panel, subject, permission and scope, for example a policy that calls `can()` on its own permission. Inside a decision it becomes a deny with a log entry. |
| `VisibilityNotSupportedException` | Exact query visibility is unavailable for a source or scope. It names the component and the category only. |

## Configuration check codes

`InvalidConfigurationException` names the failed check after the dot. A failure without a narrower check keeps the
base code `invalid_configuration`. The checks are part of the contract:

| Code | The failure |
|---|---|
| `invalid_configuration.authority_read` | The authority read route cannot give a consistent snapshot: no connection, an unsupported driver, a reconnect inside a pinned read, or SQLite without `read_uncommitted=0` |
| `invalid_configuration.authority_transaction` | Joint authority work needs a database source that stores grants and runs in one transaction |
| `invalid_configuration.cache_ttl` | A panel caches permission sets in a store without a `ttl` |
| `invalid_configuration.changing` | A change pipe or a change method is used outside its pipeline, or changes more than `until` and `fields` |
| `invalid_configuration.enum` | A panel setting holds a value that is not one of its allowed values, for example a `cache.generation` that is not a positive integer |
| `invalid_configuration.filament` | The Filament plugin is configured in a way the package cannot build |
| `invalid_configuration.filament_enforce` | A Filament resource, page or widget is neither decided by AzGuard nor excluded while `enforce` is on |
| `invalid_configuration.host_keys` | The declared host key type does not fit a storage or a model |
| `invalid_configuration.panel_state` | A panel state operation, such as a reset, runs where it is not allowed |
| `invalid_configuration.permission` | A permission key is required and missing |
| `invalid_configuration.storage` | A storage declaration or its tables do not fit the configuration |
| `invalid_configuration.tenant_scope` | A tenant panel or its assignment scope has no usable membership or owner |
