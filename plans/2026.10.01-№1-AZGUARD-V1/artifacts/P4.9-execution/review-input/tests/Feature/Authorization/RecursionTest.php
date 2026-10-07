<?php

declare(strict_types=1);

use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use Illuminate\Support\Facades\Log;

it('denies recursive policies with the exception class in the log and cleans the stack', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    RuntimePolicy::$callback = fn () => $engine->decide($panel, $request)->allowed();
    Log::spy();
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::PolicyError);
    Log::shouldHaveReceived('warning')->with('AzGuard evaluation failed.', Mockery::on(fn (array $data): bool => $data['exception'] === RecursionDetectedException::class));
    RuntimePolicy::$callback = null;
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
});
it('permits another permission from a policy', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    RuntimePolicy::$callback = fn () => $engine->decide($panel, $request)->allowed();
    expect($engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')))->allowed())->toBeTrue();
});

it('treats omitted and explicit global scope as the same recursion frame', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    RuntimePolicy::$callback = fn () => $engine->decide($panel, $request->inScope(AccessScope::in(TenantRef::global())))->allowed();
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::PolicyError)->and(RuntimePolicy::$calls)->toBe(1);
});
