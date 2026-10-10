<?php

declare(strict_types=1);

use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Auth\Access\Gate as LaravelGate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

it('documents that a permissive host callback registered before the bridge bypasses it', function (): void {
    GateWorld::seed();
    $source = new GeneratedSource;
    GateWorld::compile($source);
    $user = User::findOrFail(1);
    $gate = new LaravelGate(app(), fn () => $user);
    $gate->before(fn () => true);
    $gate->before(app(GateBridge::class));
    expect($gate->allows('admin:orders.view'))->toBeTrue()->and($source->grantReads)->toBe(0);
    expect(app(GateBridge::class)($user, 'admin:orders.view')->denied())->toBeTrue();
    Relation::morphMap([], false);
    Carbon::setTestNow();
});
