<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Builders\CompositePermissionCatalog;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;
use AzGuard\Registry\Support\RuntimePermissionBindings;
use AzGuard\Tests\Stubs\Panels\Permissions\AdminPermission;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    AzGuard::registerPanel(Panel::make()->id('admin')
        ->basePath(dirname(__DIR__, 2).'/Stubs/Panels')
        ->namespace('AzGuard\Tests\Stubs\Panels')
        ->permissionEnums([AdminPermission::class]));
});

function validateCoreCatalog(bool $strict = true): array
{
    $exit = Artisan::call('guard:catalog:validate', [
        '--panel' => 'admin', '--json' => true, '--strict' => $strict,
    ]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('preserves enum and GateAbility validation with core alone', function (): void {
    expect(iterator_to_array(app()->tagged(RuntimePermissionBindings::TAG)))->toBe([]);
    app()->instance(PermissionCatalog::class, new CompositePermissionCatalog(
        [new EnumPermissionCatalogBuilder('admin', [AdminPermission::class])],
        ['admin'],
    ));
    [$exit, $payload] = validateCoreCatalog();
    expect($exit)->toBe(0)->and($payload['errors'])->toBe([])->and($payload['warnings'])->toBe([]);
});

it('still fails when a real policy ability is missing from the catalog', function (): void {
    app()->instance(PermissionCatalog::class, new CompositePermissionCatalog([], ['admin']));
    [$exit, $payload] = validateCoreCatalog(strict: false);
    expect($exit)->toBe(1)->and($payload['errors'])->toHaveCount(1)
        ->and($payload['errors'][0])->toContain('AdminPostPolicy::view', 'admin.post.view', 'missing from PermissionCatalog');
});

it('does not trust simple dynamic or forged Filament metadata in core', function (): void {
    $definitions = [
        new SimplePermissionDefinition('admin.post.view', 'admin'),
        new SimplePermissionDefinition('admin.orphan.view', 'admin'),
        new SimplePermissionDefinition('admin.dynamic.{id}.view', 'admin', dynamic: true),
        new SimplePermissionDefinition('admin.guarded_settings_page.view', 'admin', group: 'Pages'),
    ];
    $catalog = Mockery::mock(PermissionCatalog::class);
    $catalog->shouldReceive('all')->with('admin')->andReturn($definitions);
    $catalog->shouldReceive('has')->with('admin', 'admin.post.view')->andReturnTrue();
    app()->instance(PermissionCatalog::class, $catalog);

    [$exit, $payload] = validateCoreCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(3)->and($payload['errors'])->toBe([]);
    [$exit] = validateCoreCatalog(strict: false);
    expect($exit)->toBe(0);
});

it('retains genuine enum orphan warnings in strict mode', function (): void {
    app()->instance(PermissionCatalog::class, new CompositePermissionCatalog(
        [new EnumPermissionCatalogBuilder('admin', [TestPermission::class])],
        ['admin'],
    ));
    [$exit, $payload] = validateCoreCatalog();
    expect($exit)->toBe(1)->and($payload['errors'])->toBe([])->and($payload['warnings'])->toHaveCount(3)
        ->and(implode("\n", $payload['warnings']))->toContain('admin.post.create', 'admin.post.edit', 'admin.post.delete');
});
