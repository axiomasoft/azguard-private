<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('preserves allow and deny Response code message and status through Gate inspect', function (bool $allow): void {
    [$engine, $panel, $request] = GateWorld::compile(new GeneratedSource);
    RuntimePolicy::$result = ($allow ? Response::allow('allowed', 0) : Response::denyAsNotFound('hidden', 'host-code'))->withStatus(404);
    $decision = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')));
    $response = Gate::forUser(User::findOrFail(1))->inspect('admin:orders.policy');
    expect($response->allowed())->toBe($allow)->and($response->message())->toBe($decision->message)
        ->and($response->code())->toBe($decision->code)->and($response->status())->toBe(404);
})->with([false, true]);

it('uses the decision reason as the default deny code', function (): void {
    [$engine, $panel, $request] = GateWorld::compile(new GeneratedSource);
    $decision = $engine->decide($panel, $request);
    expect(GateBridge::toGateResult($decision)->code())->toBe('not_granted');
});
