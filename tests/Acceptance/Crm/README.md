# CRM acceptance suite

An end-to-end Testbench/Pest stand on the real Authorizer, panel providers, Eloquent and storage over SQLite
`:memory:` (PostgreSQL and MySQL through `DB_CONNECTION`). The clock is fixed at 2026-10-06 12:00 UTC.

- **World.** Panels `crm` and `backoffice`; organizations A and B (tenants); projects P1–P5 (assignment scopes);
  clients C1–C6 with a composite foreign key project + tenant; people Anna, Boris, Daria and an outsider.
- **Setup.** Assignments are written through `Storage::mutate`; changes and runtime permissions go through the
  change pipeline.
- **Expectations.** Literal ids and decision reasons, each with a positive control.

Run: `DB_CONNECTION=sqlite php -d memory_limit=1G vendor/bin/pest tests/Acceptance/Crm`. The suite is part of
`composer test`.

## Cases

| Case | Tests |
|---|---|
| R01 | [TenancyTest.php](TenancyTest.php) |
| R02 | [TenancyTest.php](TenancyTest.php) |
| R03 | [SubjectApiTest.php](SubjectApiTest.php), [HttpTest.php](HttpTest.php), [QueueTest.php](QueueTest.php) |
| R04 | [SubjectApiTest.php](SubjectApiTest.php), [RequestLifecycleTest.php](../../Feature/Http/RequestLifecycleTest.php), [QueueTest.php](QueueTest.php) |
| R05 | [SubjectApiTest.php](SubjectApiTest.php), [RouteChecksTest.php](../../Feature/Http/RouteChecksTest.php) |
| R06 | [TenancyTest.php](TenancyTest.php) |
| R07 | [TenancyTest.php](TenancyTest.php), [AssignmentsTest.php](AssignmentsTest.php), [ManagersTest.php](ManagersTest.php), [PanelAccessTest.php](../../Feature/Facade/PanelAccessTest.php) |
| R09 | [ContextRolesTest.php](ContextRolesTest.php), [AssignmentsTest.php](AssignmentsTest.php) |
| R10 | [ContextRolesTest.php](ContextRolesTest.php) |
| R11 | [ContextRolesTest.php](ContextRolesTest.php) |
| R12 | [ContextRolesTest.php](ContextRolesTest.php) |
| R13 | [ContextRolesTest.php](ContextRolesTest.php) |
| R14 | [ContextRolesTest.php](ContextRolesTest.php) |
| R15 | [FreshInputsTest.php](FreshInputsTest.php), [ManagersTest.php](ManagersTest.php) |
| R16 | [ContextRolesTest.php](ContextRolesTest.php) |
| R17 | [ContextRolesTest.php](ContextRolesTest.php) |
| R18 | [NativeFiltersTest.php](NativeFiltersTest.php) |
| R19 | [ExternalScopeTest.php](ExternalScopeTest.php) |
| R20 | [NativeFiltersTest.php](NativeFiltersTest.php) |
| R21 | [PoliciesTest.php](PoliciesTest.php) |
| R22 | [PoliciesTest.php](PoliciesTest.php) |
| R23 | [DynamicActionsTest.php](DynamicActionsTest.php), [ManagersTest.php](ManagersTest.php), [PanelAccessTest.php](../../Feature/Facade/PanelAccessTest.php) |
| R24 | [SchemaTest.php](SchemaTest.php), [Grants](../../Feature/Filament/Grants) |
| R25 | [ManagersTest.php](ManagersTest.php) |
| R26 | [AssignmentsTest.php](AssignmentsTest.php) |
| R27 | [AssignmentsTest.php](AssignmentsTest.php), [GrantManagerTest.php](../../Feature/Changes/Managers/GrantManagerTest.php), [AzGuardFacadeTest.php](../../Feature/Facade/AzGuardFacadeTest.php) |
| R28 | [AssignmentsTest.php](AssignmentsTest.php), [ManagersTest.php](ManagersTest.php), [FilamentTest.php](FilamentTest.php) |
| R29 | [AssignmentsTest.php](AssignmentsTest.php), [ChangeRaceTest.php](../../Engines/ChangeRaceTest.php), [ManagersTest.php](ManagersTest.php) |
| R30 | [AssignmentsTest.php](AssignmentsTest.php) |
| R31 | [VisibilityTest.php](VisibilityTest.php) |
| R32 | [VisibilityTest.php](VisibilityTest.php) |
| R33 | [VisibilityTest.php](VisibilityTest.php) |
| R34 | [FilamentTest.php](FilamentTest.php), [Gate/VisibilityTest.php](../../Feature/Filament/Gate/VisibilityTest.php), [Gate/SurfacesTest.php](../../Feature/Filament/Gate/SurfacesTest.php) |
| R35 | [VisibilityTest.php](VisibilityTest.php), [NativeFiltersTest.php](NativeFiltersTest.php) |
| R36 | [DirectoriesTest.php](DirectoriesTest.php), [FilamentTest.php](FilamentTest.php), [SchemaTest.php](SchemaTest.php), [SchemaBuilderTest.php](../../Feature/Schema/SchemaBuilderTest.php) |
| R37 | [ManagersTest.php](ManagersTest.php), [FilamentTest.php](FilamentTest.php) |
| R38 | [SchemaTest.php](SchemaTest.php), [Grants](../../Feature/Filament/Grants) |
| R39 | [HttpTest.php](HttpTest.php), [FilamentTest.php](FilamentTest.php) |
| R40 | [AssignmentsTest.php](AssignmentsTest.php), [FinalValidationTest.php](../../Feature/Changes/FinalValidationTest.php) — grant mutation; [FilamentTest.php](FilamentTest.php) — Filament-workflow |
| R42 | [SourcesTest.php](SourcesTest.php) — independent DB/relation read witnesses; [AssignmentsTest.php](AssignmentsTest.php) — события; [ManagersTest.php](ManagersTest.php) |
| R44 | [SourcesTest.php](SourcesTest.php) |
| R45 | [BuildInputsTest.php](BuildInputsTest.php) |
| R46 | [BuildInputsTest.php](BuildInputsTest.php) |
| R47 | [RuntimeInputsTest.php](RuntimeInputsTest.php), [AssignmentsTest.php](AssignmentsTest.php), [PipesTest.php](../../Feature/Changes/PipesTest.php), [GrantManagerTest.php](../../Feature/Changes/Managers/GrantManagerTest.php), [DirectoriesTest.php](DirectoriesTest.php) |
| R48 | [BuildInputsTest.php](BuildInputsTest.php) |
| R49 | [DiagnosticsTest.php](DiagnosticsTest.php), [SchemaTest.php](SchemaTest.php) |
| R50 | [ConsumerSpiTest.php](ConsumerSpiTest.php), [ExternalScopeTest.php](ExternalScopeTest.php) |
| R51 | [ConsistencyTest.php](ConsistencyTest.php) |
| R52 | [ConsistencyTest.php](ConsistencyTest.php), [RevocationRaceTest.php](../../Engines/RevocationRaceTest.php), [ReplicaLagTest.php](../../Engines/ReplicaLagTest.php) |
| R53 | [QueueTest.php](QueueTest.php) |
| R54 | [HttpTest.php](HttpTest.php) |
| R56 | [GeneratedPanelTest.php](../../Feature/Console/Generators/GeneratedPanelTest.php) |
| R57 | [BuildInputsTest.php](BuildInputsTest.php), [BuildStateTest.php](BuildStateTest.php), [RedisStoreTest.php](../../Feature/Authorization/Cache/RedisStoreTest.php), [StateResetCommandTest.php](../../Feature/Console/StateResetCommandTest.php), [StateResetRaceTest.php](../../Engines/StateResetRaceTest.php) |
| R58 | [ScaleBatchTest.php](ScaleBatchTest.php), [ScaleVisibilityTest.php](ScaleVisibilityTest.php), [VisibilityExplainTest.php](../../Engines/VisibilityExplainTest.php) |
| R59 | [DynamicActionsTest.php](DynamicActionsTest.php), [DynamicActionRaceTest.php](../../Engines/DynamicActionRaceTest.php) |
| R61 | [AuthorityModesTest.php](AuthorityModesTest.php) |
| R62 | [AuthorityModesTest.php](AuthorityModesTest.php) |
| R63 | [AuthorityModesTest.php](AuthorityModesTest.php), [AssignmentsTest.php](AssignmentsTest.php), [HttpTest.php](HttpTest.php), [ManagersTest.php](ManagersTest.php), [OrphanCleanupTest.php](../../Feature/Changes/Managers/OrphanCleanupTest.php) |
| R64 | [VisibilityTest.php](VisibilityTest.php), [AuthorityModesTest.php](AuthorityModesTest.php) |
| R65 | [AuthorityModesTest.php](AuthorityModesTest.php), [DynamicActionsTest.php](DynamicActionsTest.php), [ManagersTest.php](ManagersTest.php) |
| R66 | [AuthorityModesTest.php](AuthorityModesTest.php), [ManagersTest.php](ManagersTest.php), [ManagerRaceTest.php](../../Engines/ManagerRaceTest.php), [GrantChecksTest.php](../../Feature/Diagnostics/GrantChecksTest.php), [DoctorCommandTest.php](../../Feature/Diagnostics/DoctorCommandTest.php) |
| R67 | [BuildInputsTest.php](BuildInputsTest.php), [ConsumerSpiTest.php](ConsumerSpiTest.php), [RuntimeInputsTest.php](RuntimeInputsTest.php) |
| R68 | [BatchTest.php](BatchTest.php), [PanelAccessTest.php](../../Feature/Facade/PanelAccessTest.php) |

## Not covered by this stand

| Case | Scenario |
|---|---|
| R08 | Two external providers with the same local external id keep separate host references, grants and queries |
| R41 | Mixed real sources with a plugin and an LDAP-style consumer |
| R43 | A complete external sync protocol (webhooks, timeouts) |
| R55 | A real Octane worker (the request lifecycle is covered by R54 with forgetScopedInstances) |
| R60 | The framework matrix with an installed external consumer |

## Scale facts

`ScaleVisibilityTest` filters 10 000 clients over 100 scopes and two role shapes: 8 000 allowed rows, selection
chunks of 500, at most 5 000 bindings per statement. `tests/Engines/VisibilityExplainTest.php` records real `EXPLAIN` output for 100 000 rows
on PostgreSQL and MySQL and asserts the indexes actually chosen, without planner hints.
