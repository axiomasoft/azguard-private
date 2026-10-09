<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\FailureKind;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;

// The closed failure taxonomy (audits/2026-10-09-consistency-design.md, step 6).

function failureState(): CodeStateToken
{
    return CodeStateToken::of('admin', 'build', str_repeat('a', 64));
}

it('classifies every reason: transient, contract or an outcome', function (): void {
    $kinds = [];
    foreach (DecisionReason::cases() as $reason) {
        $kinds[$reason->value] = FailureKind::of($reason)?->value;
    }

    expect(array_keys(array_filter($kinds, static fn (?string $kind): bool => $kind === 'transient')))->toBe(['source_error', 'consistency_error'])
        ->and(array_keys(array_filter($kinds, static fn (?string $kind): bool => $kind === 'contract')))
        ->toBe(['context_filter_error', 'policy_error', 'restriction_error', 'hook_error', 'condition_error']);
});

it('builds a failed decision only for a failure reason and keeps outcomes unfailed', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    $failed = Decision::failed(DecisionReason::ConsistencyError, failureState(), $scope, 'sources');

    expect($failed->allowed())->toBeFalse()->and($failed->failure())->toBe(FailureKind::Transient)
        ->and(Decision::deny(DecisionReason::NotGranted, failureState(), $scope)->failure())->toBeNull()
        ->and(Decision::allow(DecisionReason::Granted, failureState(), $scope)->failure())->toBeNull()
        ->and(fn () => Decision::failed(DecisionReason::NotGranted, failureState(), $scope))->toThrow(ConsistencyException::class, 'not a failure');
});

it('reports the failures of a set without changing the other decisions', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    $set = DecisionSet::of(
        Decision::allow(DecisionReason::Granted, failureState(), $scope),
        Decision::failed(DecisionReason::SourceError, failureState(), $scope),
        Decision::deny(DecisionReason::PolicyError, failureState(), $scope),
    );

    expect($set->failures())->toBe([1, 2])->and($set->failure())->toBe(FailureKind::Contract)
        ->and($set->get(0)->allowed())->toBeTrue()
        ->and(DecisionSet::of(Decision::allow(DecisionReason::Granted, failureState(), $scope))->failure())->toBeNull()
        ->and(DecisionSet::of(Decision::failed(DecisionReason::SourceError, failureState(), $scope))->failure())->toBe(FailureKind::Transient);
});
