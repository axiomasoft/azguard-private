<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ScopedResource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('honors explicit panel and prefix while local names use the current panel', function (): void {
    [, , , $current, $registry] = GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    $current->set($registry->get('cabinet'));
    $user = User::findOrFail(1);
    expect(Gate::forUser($user)->allows('admin:orders.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('admin.orders.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('orders.view'))->toBeFalse()
        ->and(fn () => app(PanelResolver::class)->resolve($user, 'admin:orders.view', 'cabinet'))->toThrow(ConflictingPanelException::class);
});

it('carries resource tenant and context through the common boundary and rejects a conflicting owner', function (): void {
    $s = new ScenarioGenerator(2);
    [$engine, $panel, $request] = $s->world([new GeneratedSource(direct: [$s->grant($s->scope(true))])], mode: 'inherit');
    $resource = new ScopedResource($s->scope(true));
    $request = $request->inScope($s->scope(true), $resource);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()
        ->and(Gate::forUser($s->subject)->allows($request->permission()->full(), $s->gateArguments($request)))->toBeTrue();
    $conflict = AccessScope::in($s->foreignTenant, $s->context);
    expect(Gate::forUser($s->subject)->allows($request->permission()->full(), [$resource, $conflict]))->toBeFalse();
});
