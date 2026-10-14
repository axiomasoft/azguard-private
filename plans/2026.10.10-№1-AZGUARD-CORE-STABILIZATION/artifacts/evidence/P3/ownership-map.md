# P3 final ownership map and module activation matrix

Item PLAN1.P3.14. Data: `tests/Arch/Fixtures/target-map.json` (ownership oracle read by `TargetPlacementTest`, `OwnershipScanTest`, `AutoloadOwnershipTest`) and `tests/Feature/Activation/ActivationMatrixTest.php`. Source scan taken at base HEAD `9788f87c18bfbff57b733d1709ed655e922ad6cf` (P3.13) with the P3.14 documentation on top.

## Placement by owner

Oracle rows that are not retired, grouped by the owner of their target path (a split row counts once per target path). `files` is the number of PHP files under the owner's production root now.

| Owner | Production root | Oracle rows | PHP files |
|:--|:--|--:|--:|
| core | `packages/core/src` | 416 | 408 |
| scopes | `packages/core/modules/scopes/src` | 25 | 22 |
| audit | `packages/core/modules/audit/src` | 10 | 10 |
| devtools | `packages/core/modules/devtools/src` | 21 | 21 |
| filament | `packages/filament/src` | 41 | 41 |

Retired rows: 6 (no target path). Total oracle rows: 513.

### Scopes module `AzGuard\Scopes`: target types of the oracle

| Target | Marker | Action |
|:--|:--|:--|
| `AzGuard\Authorization\Internal\Query\ResourceContextMapping + AzGuard\Scopes\Internal\Query\ScopeVisibility` | internal | split |
| `AzGuard\Diagnostics\Internal\Checks\TenantMembershipConfigured + AzGuard\Scopes\Internal\Checks\ScopeMembershipConfigured` | internal | split |
| `AzGuard\Panels\Internal\CanonicalMetadata + AzGuard\Scopes\Internal\ScopeConfiguration` | internal | split |
| `AzGuard\Scopes\AssignmentScopeSettings` | Api | relocate |
| `AzGuard\Scopes\BaseAssignmentScope` | Spi(Implement) | relocate |
| `AzGuard\Scopes\ContextAware` | Api | relocate |
| `AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter` | Spi(Implement) | move |
| `AzGuard\Scopes\Contracts\AssignmentScopeFilter` | Spi(Implement) | move |
| `AzGuard\Scopes\Contracts\AssignmentScopeMembership` | Spi(Implement) | move |
| `AzGuard\Scopes\Contracts\ConfigurableAssignmentScopeDefinition` | Spi(Implement) | move |
| `AzGuard\Scopes\Internal\Checks\ScopeMembershipConfigured` | internal | new |
| `AzGuard\Scopes\Internal\QueryScopeDirectory` | internal | relocate |
| `AzGuard\Scopes\Internal\Query\EligibilityBuilder` | internal | relocate |
| `AzGuard\Scopes\Internal\Query\FilterGuard` | internal | new |
| `AzGuard\Scopes\Internal\Query\FilterQuery` | internal | new |
| `AzGuard\Scopes\Internal\Query\ScopeVisibility` | internal | new |
| `AzGuard\Scopes\Internal\RoleBindings` | internal | relocate |
| `AzGuard\Scopes\Internal\ScopeEligibility` | internal | relocate |
| `AzGuard\Scopes\Internal\ScopeEvaluator` | internal | new |
| `AzGuard\Scopes\Internal\ScopeMembershipRestriction` | internal | new |
| `AzGuard\Scopes\Internal\ScopesOptions` | internal | new |
| `AzGuard\Scopes\ModelAssignmentScopeDefinition` | Api | relocate |
| `AzGuard\Scopes\ScopesPlugin` | Api | rename |
| `AzGuard\Scopes\Testing\AssignmentScopeFilterContractTests` | Spi(Call) | new |
| `AzGuard\Tenancy\Internal\TenantMembershipRestriction + AzGuard\Scopes\Internal\ScopeMembershipRestriction` | internal | split |

### Audit module `AzGuard\Audit`: target types of the oracle

| Target | Marker | Action |
|:--|:--|:--|
| `AzGuard\Audit\AuditOptions` | Api | new |
| `AzGuard\Audit\AuditPlugin` | Api | move |
| `AzGuard\Audit\AuditServiceProvider` | Api | new |
| `AzGuard\Audit\Internal\AuditSchema` | internal | new |
| `AzGuard\Audit\Internal\Checks\AuditTableExists` | internal | move |
| `AzGuard\Audit\Internal\Console\AuditMigrationCommand` | internal | new |
| `AzGuard\Audit\Internal\Console\AuditPruneCommand` | internal | move |
| `AzGuard\Audit\Internal\JournalParticipant` | internal | rename |
| `AzGuard\Audit\Internal\JournalPruner` | internal | new |
| `AzGuard\Audit\Internal\JournalRow` | internal | split |

### DevTools module `AzGuard\DevTools`: target types of the oracle

| Target | Marker | Action |
|:--|:--|:--|
| `AzGuard\DevTools\DevToolsServiceProvider` | Api | new |
| `AzGuard\DevTools\Internal\Console\InstallCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakeCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakeModelsCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakePanelCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakePermissionCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakePipeCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakePluginCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakePolicyCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakeRestrictionCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakeRoleCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\Make\MakeSourceCommand` | internal | move |
| `AzGuard\DevTools\Internal\Console\StubsCommand` | internal | move |
| `AzGuard\DevTools\Internal\ScaffoldConfig` | internal | new |
| `AzGuard\DevTools\Internal\Scaffold\EnumSource` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\GeneratedFile` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\Layout` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\Place` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\PolicyFile` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\ProviderRegistration` | internal | move |
| `AzGuard\DevTools\Internal\Scaffold\StubStore` | internal | move |

## Dependency map (verified edges)

Imports of another zone's namespace found by scanning each production root for `AzGuard\Scopes\`, `AzGuard\Audit\`, `AzGuard\DevTools\` (the rule is enforced by `OwnershipScanTest` I2/I3 and `ZonesArchTest`):

| Importer | Module namespaces it names |
|:--|:--|
| core | none |
| Scopes | none |
| Audit | none |
| DevTools | none |
| Filament | none |

```text
Kernel values
  ^
Core contracts and shared values (Contracts/*, Context, Tenancy, Schema, Panels)
  ^                ^                      ^
Decision/visibility/write      Storage and standard         Core Laravel composition
coordinators                   adapters                     (AzGuardServiceProvider)
  ^
Scopes module   Audit module   DevTools module   Filament package
(each depends on core only; none depends on another module; core depends on none)
```

Distribution metadata (`packages/core/composer.json` `extra.laravel.providers` and PSR-4 roots) is the only place core's package names a module.

## Module activation matrix

From `tests/Feature/Activation/ActivationMatrixTest.php` (two panels over one storage; each row has a control that shows the assertion can fail):

| Row | Configuration | Result |
|:--|:--|:--|
| 1 | core only | boot, check, grant, revoke, visibility and doctor resolve no module service, run no query on a module table; no `audit_log`; `scopeDeclaration()` is null (mode `None`) |
| 2 | Scopes on panel A, not on B | A interprets mode and aliases; B refuses a scope (`assignment_scope_not_accepted`) and its stored scoped rows are inert |
| 3 | Audit on A, not on B, one storage | A journals the event it dispatched (same `event_id`); B writes no row |
| 4 | two panels, different options | own mode and retention per panel; fingerprints differ; options objects are not shared |
| 5 | module removed after use | rows stay; scopes fail closed; journaling stops; revoke, expiry and prune still work |
| 6 | DevTools outside the console | no `azguard:make:*`, `azguard:install`, `azguard:stubs`; the `scaffold` section is still validated by its owner |

Core without module roots (I13, I19): `bin/consumer-fixture.sh core-only` installs the core archive without `modules/` and with `AzGuardServiceProvider` as the only provider, then migrates, runs `azguard:doctor`, one grant and one check.
