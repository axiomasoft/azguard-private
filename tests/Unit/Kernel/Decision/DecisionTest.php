<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;

function decisionState(): CodeStateToken
{
    return CodeStateToken::of('admin', 'build-1', 'f1');
}

dataset('effect and reason', function (): array {
    $allow = [DecisionReason::Granted, DecisionReason::SuperAdmin, DecisionReason::Policy];
    $rows = [];

    foreach (Effect::cases() as $effect) {
        foreach (DecisionReason::cases() as $reason) {
            $rows[$effect->value.' / '.$reason->value] = [$effect, $reason, match ($effect) {
                Effect::Allow => in_array($reason, $allow, true),
                Effect::NotApplicable => $reason === DecisionReason::NotApplicable,
                Effect::Deny => ! in_array($reason, [DecisionReason::Granted, DecisionReason::SuperAdmin, DecisionReason::NotApplicable], true),
            }];
        }
    }

    return $rows;
});

it('keeps every reason of the dossier with its machine code', function (): void {
    expect(array_map(static fn (DecisionReason $r): string => $r->value, DecisionReason::cases()))->toBe([
        'granted', 'super_admin', 'hook', 'policy', 'not_granted', 'not_applicable', 'context_required',
        'context_not_accepted', 'context_ineligible', 'context_filter_error', 'restricted', 'source_error',
        'policy_error', 'restriction_error', 'hook_error', 'tenant_required', 'tenant_mismatch', 'context_mismatch',
        'resource_scope_missing', 'condition_error', 'consistency_error',
    ])->and(array_map(static fn (Effect $e): string => $e->value, Effect::cases()))->toBe(['allow', 'deny', 'not_applicable']);
});

it('admits a reason for an effect only when they agree', function (Effect $effect, DecisionReason $reason, bool $admitted): void {
    $scope = AccessScope::in(TenantRef::global());
    $make = match ($effect) {
        Effect::Allow => fn () => Decision::allow($reason, decisionState(), $scope),
        Effect::Deny => fn () => Decision::deny($reason, decisionState(), $scope),
        Effect::NotApplicable => $reason === DecisionReason::NotApplicable
            ? fn () => Decision::notApplicable(decisionState(), $scope)
            : null,
    };

    expect(Decision::admits($effect, $reason))->toBe($admitted);

    if ($make === null) {
        return;
    }

    $admitted
        ? expect($make()->reason)->toBe($reason)->and($make()->effect)->toBe($effect)
        : expect($make)->toThrow(ConsistencyException::class, sprintf('Decision effect "%s" cannot have reason "%s".', $effect->value, $reason->value));
})->with('effect and reason');

it('carries state, scope, component and traced grants', function (): void {
    $scope = AccessScope::in(TenantRef::of('org', 1));
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.*'), 'folder', $scope);
    $allow = Decision::allow(DecisionReason::Granted, decisionState(), $scope, 'folder', [$grant]);
    $deny = Decision::deny(DecisionReason::Restricted, decisionState(), $scope, 'blocked-users');

    expect($allow->allowed())->toBeTrue()
        ->and($allow->grants)->toBe([$grant])
        ->and($allow->component)->toBe('folder')
        ->and($allow->scope)->toBe($scope)
        ->and($deny->allowed())->toBeFalse()
        ->and($deny->grants)->toBe([])
        ->and(Decision::notApplicable(decisionState(), $scope)->allowed())->toBeFalse();
});

it('keys the states of a mixed set apart', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    $code = CodeStateToken::of('admin', 'build-1', 'f1');
    $stored = StateToken::of('default', 'admin', 'inc-1', 3, 0, 'f2');
    $set = DecisionSet::of(
        Decision::allow(DecisionReason::Policy, $code, $scope),
        Decision::deny(DecisionReason::NotGranted, $stored, $scope),
        Decision::allow(DecisionReason::Granted, $stored, $scope),
    );

    expect($set)->toHaveCount(3)
        ->and($set->get(1)->reason)->toBe(DecisionReason::NotGranted)
        ->and(iterator_to_array($set))->toHaveCount(3)
        ->and($set->states())->toBe([
            '[1,"code","admin","build-1"]' => $code,
            '[1,"storage","default","admin"]' => $stored,
        ])
        ->and(fn () => $set->get(3))->toThrow(OutOfRangeException::class)
        ->and(DecisionSet::of())->toHaveCount(0);
});

it('refuses two different tokens for one storage panel in a set', function (): void {
    $scope = AccessScope::in(TenantRef::global());

    expect(fn () => DecisionSet::of(
        Decision::allow(DecisionReason::Granted, StateToken::of('default', 'admin', 'inc-1', 3, 0, 'f'), $scope),
        Decision::allow(DecisionReason::Granted, StateToken::of('default', 'admin', 'inc-1', 4, 0, 'f'), $scope),
    ))->toThrow(ConsistencyException::class, 'Decisions of one set carry different state tokens for ');
});
