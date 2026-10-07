<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('P01b rejects bare wildcard contributions rather than manufacturing superadmin', function (string $wildcard): void {
    expect(fn () => PermissionPattern::of('admin', $wildcard))->toThrow(InvalidPermissionKeyException::class);

    // Simulate an untrusted source bypassing the validated value-object constructor.
    $reflection = new ReflectionClass(PermissionPattern::class);
    $pattern = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('panel')->setValue($pattern, 'admin');
    $reflection->getProperty('local')->setValue($pattern, $wildcard);
    $scope = AccessScope::in(TenantRef::global());
    $source = new GeneratedSource(direct: [Grant::of($pattern, 'generated', $scope)], roles: [RoleContribution::of(RoleKey::of('admin', 'root'), $scope, 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, 'none', fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class]), tenant: false);

    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeFalse();
})->with(['*', '**']);

it('P01b fails closed on a bare wildcard DB row written through mutate', function (string $wildcard): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => $wildcard])]);
    DatabaseWorld::insert('role', [DatabaseWorld::row('role', overrides: ['role' => 'root'])]);
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource, 'none', fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class])->permissions([DatabaseSource::make()]), tenant: false);

    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError)
        ->and($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeFalse();
    app(StorageSchema::class)->drop('default');
})->with(['*', '**']);

it('P01b uses the same compiled superadmin flag for code and database contributions', function (bool $database): void {
    app(StorageSchema::class)->create('default');
    $scope = AccessScope::in(TenantRef::global());

    if ($database) {
        DatabaseWorld::insert('role', [DatabaseWorld::row('role', overrides: ['role' => 'root'])]);
    }
    $source = new GeneratedSource(roles: $database ? [] : [RoleContribution::of(RoleKey::of('admin', 'root'), $scope, 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, 'none', fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class])->permissions([DatabaseSource::make()]), tenant: false);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SuperAdmin)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeTrue();
    app(StorageSchema::class)->drop('default');
})->with([false, true]);
