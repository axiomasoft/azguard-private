<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;

it('carries scalar policy response details on deny and allow without changing the effect', function (string|int|float|bool|null $code): void {
    $state = CodeStateToken::of('admin', 'build', 'fingerprint');
    $scope = AccessScope::in(TenantRef::global());
    $deny = Decision::deny(DecisionReason::Policy, $state, $scope, message: 'Closed', status: 404, code: $code);
    $allow = Decision::allow(DecisionReason::Policy, $state, $scope, message: 'Open', status: 202, code: $code);

    expect($deny->allowed())->toBeFalse()
        ->and($deny->message)->toBe('Closed')->and($deny->status)->toBe(404)->and($deny->code)->toBe($code)
        ->and($allow->allowed())->toBeTrue()
        ->and($allow->message)->toBe('Open')->and($allow->status)->toBe(202)->and($allow->code)->toBe($code)
        ->and($deny->state)->toBe($state)->and($allow->scope)->toBe($scope)
        ->and(Decision::admits(Effect::Allow, DecisionReason::Policy))->toBeTrue()
        ->and(Decision::admits(Effect::Deny, DecisionReason::Policy))->toBeTrue();
})->with(['text' => ['orders.closed'], 'integer' => [7], 'float' => [1.5], 'boolean' => [false], 'none' => [null]]);

it('keeps details nullable by default and preserves the effect-reason consistency guard', function (): void {
    $state = CodeStateToken::of('admin', 'build', 'fingerprint');
    $scope = AccessScope::in(TenantRef::global());
    foreach ([Decision::allow(DecisionReason::Granted, $state, $scope), Decision::deny(DecisionReason::NotGranted, $state, $scope), Decision::notApplicable($state, $scope)] as $decision) {
        expect($decision->message)->toBeNull()->and($decision->status)->toBeNull()->and($decision->code)->toBeNull();
    }
    expect(fn () => Decision::allow(DecisionReason::Restricted, $state, $scope, message: 'denied'))->toThrow(ConsistencyException::class);
});
