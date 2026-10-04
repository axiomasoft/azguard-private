<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseRoleGrant;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('builds immutable independent sources and describes database capabilities', function (): void {
    $base = DatabaseSource::make();
    $roles = $base->rolesOnly();
    $dynamic = $base->dynamicPermissions();
    [$panel] = DatabaseWorld::compile($base);
    $description = $base->describe($panel);

    expect($base->id())->toBe('database')->and($base->isRolesOnly())->toBeFalse()->and($roles->isRolesOnly())->toBeTrue()
        ->and($base->isDynamic())->toBeFalse()->and($dynamic->isDynamic())->toBeTrue()
        ->and($base->volatility())->toBe(Volatility::Stable)->and($description->dynamic)->toBeFalse();
    foreach ([ProvidesPermissions::class, ProvidesGrants::class, ProvidesRoleGrants::class, StoresGrants::class,
        FiltersQueries::class, FencesReads::class, DescribesSchema::class] as $capability) {
        expect($base)->toBeInstanceOf($capability)->and($description->capabilities)->toContain($capability);
    }
    app(SourceManager::class)->extend('database', static fn () => DatabaseSource::make());
    $first = app(SourceManager::class)->make('database', 'admin');
    $second = app(SourceManager::class)->make('database', 'cabinet');
    expect($first)->toBeInstanceOf(DatabaseSource::class)->and($first)->not->toBe($second);
});

it('validates incompatible model kinds and preserves valid custom configuration', function (): void {
    expect(fn () => DatabaseSource::make()->models(roleGrant: PermissionGrant::class))->toThrow(StorageMismatchException::class);
    $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class);
    [$panel] = DatabaseWorld::compile($source);
    expect($source->describe($panel)->id)->toBe('database');
});

it('runs writer callbacks inside the bound authority mutation and rolls back failures', function (): void {
    $source = DatabaseSource::make();
    DatabaseWorld::compile($source);
    $result = $source->transaction(function (): string {
        expect(DatabaseWorld::storage()->connection()->transactionLevel())->toBeGreaterThan(0);
        DatabaseWorld::storage()->table('permission_grants')->insert(DatabaseWorld::row('permission'));

        return 'done';
    });
    expect($result)->toBe('done')->and(DatabaseWorld::storage()->table('permission_grants')->count())->toBe(1);
    expect(fn () => $source->transaction(function (): void {
        DatabaseWorld::storage()->table('permission_grants')->insert(DatabaseWorld::row('permission', overrides: ['origin' => 'rollback']));

        throw new RuntimeException('abort');
    }))->toThrow(RuntimeException::class, 'abort');
    expect(DatabaseWorld::storage()->table('permission_grants')->count())->toBe(1);
});
