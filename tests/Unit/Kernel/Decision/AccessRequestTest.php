<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

it('starts without tenant, context, resource or trace', function (): void {
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view'));

    expect($request->subject()->key())->toBe('user:1')
        ->and($request->permission()->full())->toBe('admin:orders.view')
        ->and($request->tenant())->toBeNull()
        ->and($request->context())->toBeNull()
        ->and($request->resource())->toBeNull()
        ->and($request->isTraced())->toBeFalse();
});

it('returns a new request from every wither and keeps the original', function (): void {
    $base = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view'));
    $tenant = TenantRef::of('org', 1);
    $project = AssignmentScopeRef::of('project', 7);
    $resource = new stdClass;

    $scoped = $base->inTenant($tenant)->on($project, $resource)->traced();

    expect($scoped)->not->toBe($base)
        ->and($base->tenant())->toBeNull()
        ->and($base->context())->toBeNull()
        ->and($base->isTraced())->toBeFalse()
        ->and($scoped->tenant())->toBe($tenant)
        ->and($scoped->context())->toBe($project)
        ->and($scoped->resource())->toBe($resource)
        ->and($scoped->isTraced())->toBeTrue()
        ->and($scoped->traced(false)->isTraced())->toBeFalse();
});

it('keeps the tenant when the context changes and treats null as not given', function (): void {
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view'))
        ->inTenant(TenantRef::of('org', 1));

    expect($request->on(null)->tenant()?->key())->toBe('org:1')
        ->and($request->on(null)->context())->toBeNull()
        ->and($request->on(AssignmentScopeRef::global())->context()?->isGlobal())->toBeTrue();
});

it('sets tenant and context together from a scope', function (): void {
    $scope = AccessScope::in(TenantRef::of('org', 2), AssignmentScopeRef::of('project', 7));
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view'))
        ->inTenant(TenantRef::of('org', 1))
        ->inScope($scope);

    expect($request->tenant())->toBe($scope->tenant)
        ->and($request->context())->toBe($scope->context)
        ->and($request->resource())->toBeNull();
});
