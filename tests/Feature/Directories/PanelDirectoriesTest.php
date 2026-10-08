<?php

declare(strict_types=1);

use AzGuard\Directories\DirectoryResolver;
use AzGuard\Directories\PanelDirectories;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

/*
 * G1: the directories of a panel through the public panel access are the ones the internal resolver picks; the access
 * adds no search of its own.
 */

beforeEach(fn () => World::seed());

afterEach(function (): void {
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('hands out the directories that the resolver of the panel picks', function (): void {
    $panel = World::compile();
    Facade::clearResolvedInstances();
    $directories = AzGuard::panel('crm')->directories();
    $resolver = DirectoryResolver::for($panel, app());

    expect($directories)->toBeInstanceOf(PanelDirectories::class)
        ->and($directories->subjects())->toEqual($resolver->subjects())
        ->and($directories->subjects('crm.user'))->toEqual($resolver->subjects('crm.user'))
        ->and($directories->subjects('unknown.type'))->toEqual($resolver->subjects('unknown.type'))
        ->and($directories->tenants())->toEqual($resolver->tenants())
        ->and($directories->scopes('crm.project'))->toEqual($resolver->scopes('crm.project'));
});

it('searches and describes the same tenants as the resolver', function (): void {
    $panel = World::compile();
    Facade::clearResolvedInstances();
    $lookup = Lookups::make($panel, target: null);
    $tenants = AzGuard::panel('crm')->inTenant(TenantRef::of('crm.organization', 1))->directories()->tenants();

    expect(array_map(fn ($o) => $o->tenant->key(), $tenants->search('', $lookup, 50)))
        ->toBe(array_map(fn ($o) => $o->tenant->key(), DirectoryResolver::for($panel, app())->tenants()->search('', $lookup, 50)))
        ->and($tenants->describe(TenantRef::of('crm.organization', 2), $lookup)?->tenant->key())->toBe('crm.organization:2')
        ->and($tenants->describe(TenantRef::of('crm.organization', 99), $lookup))->toBeNull();
});
