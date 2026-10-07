<?php

declare(strict_types=1);

use AzGuard\Directories\DirectoryResolver;
use AzGuard\Directories\ModelTenantDirectory;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

beforeEach(fn () => World::seed());

afterEach(function (): void {
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('lists the tenants of the panel model by key and limit without deciding membership', function (): void {
    $panel = World::compile();
    $directory = DirectoryResolver::for($panel, app())->tenants();
    $lookup = Lookups::make($panel, target: null, phase: AssignmentScopePhase::Assignment);

    expect($directory)->toBeInstanceOf(ModelTenantDirectory::class)
        ->and(array_map(fn ($o) => $o->tenant->key(), $directory->search('', $lookup, 10)))->toBe(['crm.organization:1', 'crm.organization:2'])
        ->and(array_map(fn ($o) => $o->tenant->id(), $directory->search('', $lookup, 1)))->toBe(['1'])
        ->and(array_map(fn ($o) => $o->tenant->id(), $directory->search('2', $lookup, 10)))->toBe(['2'])
        ->and($directory->search('2', $lookup, 0))->toBe([])
        ->and($directory->search('99', $lookup, 10))->toBe([]);
});

it('describes a known tenant and nothing for a missing, foreign-type or global one', function (): void {
    $panel = World::compile();
    $directory = DirectoryResolver::for($panel, app())->tenants();
    $lookup = Lookups::make($panel, target: null);

    expect($directory->describe(TenantRef::of('crm.organization', 2), $lookup)?->label)->toBe('2')
        ->and($directory->describe(TenantRef::of('crm.organization', 99), $lookup))->toBeNull()
        ->and($directory->describe(TenantRef::of('other.type', 1), $lookup))->toBeNull()
        ->and($directory->describe(TenantRef::global(), $lookup))->toBeNull();
});

it('offers no tenants for a panel without a tenant model', function (): void {
    World::compile(backoffice: fn (PanelBuilder $p) => $p->tenants(TenantPolicy::none())->scopes(AssignmentScopePolicy::none()));
    $panel = app(PanelRegistry::class)->get('backoffice');
    $directory = DirectoryResolver::for($panel, app())->tenants();
    $lookup = Lookups::make($panel, target: null);

    expect($directory->search('', $lookup, 10))->toBe([])
        ->and($directory->describe(TenantRef::of('crm.organization', 1), $lookup))->toBeNull();
});
