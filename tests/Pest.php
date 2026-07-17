<?php

declare(strict_types=1);

use AzGuard\Tests\ContextTestCase;
use AzGuard\Tests\FilamentTestCase;
use AzGuard\Tests\ManagerSwapTestCase;
use AzGuard\Tests\MorphTypeTestCase;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)
    ->in('Unit');

uses(TestCase::class, RefreshDatabase::class)
    ->in('Feature/AbilitiesForTest.php',
        'Feature/AccessControlTest.php',
        'Feature/AccessDecisionTest.php',
        'Feature/AuthorizerExtendedTest.php',
        'Feature/AuthorizerPanelResolutionTest.php',
        'Feature/AuthorizerTest.php',
        'Feature/CacheResetCommandTest.php',
        'Feature/CatalogLazyPanelsTest.php',
        'Feature/CheckAccessMiddlewareTest.php',
        'Feature/ClassPermissionTest.php',
        'Feature/CrossRequestCacheInvalidationTest.php',
        'Feature/CustomCatalogBuilderTest.php',
        'Feature/CustomGrantSourceTest.php',
        'Feature/DatabaseRoleGrantSourceTest.php',
        'Feature/DirectGrantSourceTest.php',
        'Feature/DiscoveryTest.php',
        'Feature/EnumPermissionArgumentTest.php',
        'Feature/EnumRolePermissionsTest.php',
        'Feature/ExplainAbilitiesCommandTest.php',
        'Feature/ExtensionSwapTest.php',
        'Feature/FakeGrantSourceTest.php',
        'Feature/DoctorCommandTest.php',
        'Feature/StructuredOutputCommandsTest.php',
        'Feature/GateIntegrationScopedTest.php',
        'Feature/GrantsCliColumnsTest.php',
        'Feature/GrantsFacadeDefaultPanelTest.php',
        'Feature/HasDirectGrantsTest.php',
        'Feature/InstallCommandTest.php',
        'Feature/IntegrationPolishTest.php',
        'Feature/ListScopedRolesCommandTest.php',
        'Feature/LoadAzGuardRolesMiddlewareTest.php',
        'Feature/MakeGuardForceGenerationTest.php',
        'Feature/MakeGuardPanelCommandTest.php',
        'Feature/PanelEnumIdentityTest.php',
        'Feature/PanelPermissionResolverTest.php',
        'Feature/PermissionAccessTest.php',
        'Feature/PermissionCacheEpochInvalidationTest.php',
        'Feature/PermissionMapTest.php',
        'Feature/PolicyAttributeRegistrarTest.php',
        'Feature/RoleAssignmentCommandTest.php',
        'Feature/RoleClassResolutionTest.php',
        'Feature/RolePermissionsCommandTest.php',
        'Feature/RolePermissionValidationTest.php',
        'Feature/ScopedPermissionEnumResolutionTest.php',
        'Feature/ScopedRolePanelIsolationTest.php',
        'Feature/SetCurrentPanelMiddlewareTest.php',
        'Feature/SuperAdminCommandTest.php',
        'Feature/SyncRolesCommandTest.php',
        'Feature/WildcardCatalogFilterTest.php',
    );

uses(ManagerSwapTestCase::class, RefreshDatabase::class)
    ->in('Feature/ManagerSwapTest.php');

uses(ContextTestCase::class, RefreshDatabase::class)
    ->in('Feature/Context');

uses(FilamentTestCase::class, RefreshDatabase::class)
    ->in('Feature/Filament');

uses(MorphTypeTestCase::class, RefreshDatabase::class)
    ->in('Feature/MorphTypeTest.php');

uses(TestCase::class, RefreshDatabase::class)
    ->in('Feature/CliCommandMatrixTest.php');

uses(RefreshDatabase::class)
    ->in('Unit');

function createUserWithRole(string $roleName): User
{
    /** @var User $user */
    $user = User::factory()->create();

    $user->assignRole($roleName);

    return $user;
}
