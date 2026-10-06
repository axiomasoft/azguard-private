<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

it('picks the panel of a full name without a current panel and throws for a local name', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet();
    $user = new User;

    expect($current->get())->toBeNull()
        ->and($resolver->resolve($user, 'admin:users.delete')['panel']->id())->toBe('admin')
        ->and($resolver->owner('admin:users.delete', $user)?->id())->toBe('admin')
        ->and($resolver->owner('admin.users.delete', $user)?->id())->toBe('admin')
        ->and(fn () => $resolver->resolve($user, 'users.delete'))->toThrow(PanelNotResolvedException::class);
});

it('P09 Gate uses the explicit panel without a route hint and the local current panel when supplied', function (): void {
    GateWorld::seed();
    [, , , $current, $registry] = GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    $user = User::findOrFail(1);
    expect(Gate::forUser($user)->allows('admin:orders.view'))->toBeTrue();
    $current->set($registry->get('cabinet'));
    expect(Gate::forUser($user)->allows('admin:orders.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('orders.view'))->toBeFalse();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});
