<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;

it('builds the pure boolean grammar without query objects', function (): void {
    $tree = P::all(P::any(P::eq('city', 'Paris'), P::in('id', [1, null])), P::not(P::isNull('city')),
        P::notNull('id'), P::gte('score', 10), P::lt('score', 20), P::exists('project.members', P::eq('active', true)));

    expect($tree->operation)->toBe('all')->and($tree->operands)->toHaveCount(6)
        ->and($tree->operands[5]->identifier)->toBe('project.members')
        ->and(P::all()->operands)->toBe([])->and(P::any()->operands)->toBe([]);
    $tree->assertBoolean();
});

it('preserves scalar policy outcomes in an explicit partition', function (?bool $result, string $outcome): void {
    $partition = P::policyResult($result);
    $partition->assertPartition();
    foreach (['allow', 'deny', 'abstain'] as $name) {
        expect($partition->outcome($name)->operation)->toBe($name === $outcome ? 'pass' : 'deny');
    }
})->with([[true, 'allow'], [false, 'deny'], [null, 'abstain']]);

it('limits before outcomes to deny and pass', function (BeforeResult $result, string $outcome): void {
    $partition = P::beforeResult($result);
    $partition->assertPartition(before: true);
    expect($partition->outcome($outcome)->operation)->toBe('pass');
    expect(fn () => $partition->outcome('allow'))->toThrow(InvalidSourceContributionException::class);
    expect(fn () => $partition->assertPartition())->toThrow(InvalidSourceContributionException::class);
})->with([[BeforeResult::Continue, 'pass'], [BeforeResult::Deny, 'deny']]);

it('requires selecting an outcome before boolean composition', function (): void {
    $partition = P::partition(P::eq('state', 'allow'), P::eq('state', 'deny'), P::isNull('state'));
    expect(fn () => P::all($partition))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::not($partition))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::exists('project', $partition))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::partition($partition, P::deny(), P::deny()))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => $partition->outcome('pass'))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::pass()->outcome('allow'))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::pass()->assertPartition())->toThrow(InvalidSourceContributionException::class);
});

it('rejects unsafe column and relation identifiers', function (string $identifier): void {
    expect(fn () => P::eq($identifier, 1))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::exists($identifier, P::pass()))->toThrow(InvalidSourceContributionException::class);
})->with(['', '*', 'id = 1', 'id--', 'id;delete', 'id()', 'id->key', 'id"', '.id', 'id.']);

it('rejects qualified columns while accepting dotted relation paths', function (): void {
    expect(fn () => P::eq('projects.id', 1))->toThrow(InvalidSourceContributionException::class)
        ->and(P::exists('project.members', P::pass())->identifier)->toBe('project.members');
});

it('rejects malformed or nonliteral bindings', function (array $values): void {
    expect(fn () => P::in('id', $values))->toThrow(InvalidSourceContributionException::class);
})->with([
    'map' => [['id' => 1]], 'nested' => [[[1]]], 'object' => [[new stdClass]],
    'infinity' => [[INF]], 'nan' => [[NAN]],
]);

it('rejects named variadic operands instead of losing their positional grammar', function (): void {
    expect(fn () => P::all(predicate: P::pass()))->toThrow(InvalidSourceContributionException::class);
});

it('keeps unsupported sibling outcomes visible when selecting a partition', function (): void {
    $partition = P::partition(P::pass(), P::unsupported(), P::deny());
    expect($partition->isSupported())->toBeFalse()
        ->and($partition->outcome('allow')->operation)->toBe('unsupported')
        ->and(P::all(P::pass(), P::unsupported())->isSupported())->toBeFalse()
        ->and(P::policyResult(null)->isSupported())->toBeTrue();
});

it('rejects constant partitions with overlapping or missing outcomes', function (): void {
    expect(fn () => P::partition(P::pass(), P::pass(), P::deny()))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::partition(P::deny(), P::deny(), P::deny()))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::beforePartition(P::pass(), P::pass()))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::beforePartition(P::deny(), P::deny()))->toThrow(InvalidSourceContributionException::class);
});

it('detaches binding references and preserves literal strings', function (): void {
    $value = "x' OR 1=1 --";
    $values = [&$value];
    $predicate = P::in('city', $values);
    $value = 'changed';
    expect($predicate->values)->toBe(["x' OR 1=1 --"])
        ->and(P::in('id', [true, 3, 1.5, null])->values)->toBe([true, 3, 1.5, null]);
});

it('binds a whole branch to one concrete typed witness', function (bool $role): void {
    $scope = AccessScope::in(TenantRef::global());
    $first = $role
        ? RoleContribution::of(RoleKey::of('admin', 'reader'), $scope, 'folder', fields: ['city' => 'Paris'])
        : Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', $scope, fields: ['city' => 'Paris']);
    $second = $role
        ? RoleContribution::of(RoleKey::of('admin', 'reader'), $scope, 'folder', fields: ['city' => 'Paris'])
        : Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', $scope, fields: ['city' => 'Paris']);
    $branch = P::branch($first, P::all(P::eq('city', $first->fields()['city']), P::gte('score', 10)));
    $branch->assertContribution($first);
    expect($branch->contribution)->toBe($first)
        ->and(fn () => P::branch($second, P::any(P::pass(), $branch)))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => P::branch($first, P::policyResult(true)))->toThrow(InvalidSourceContributionException::class);
})->with([false, true]);
