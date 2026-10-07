<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;

it('builds a grant with the manual origin by default', function (): void {
    $scope = AccessScope::in(TenantRef::of('org', 1));
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.*'), 'relation:project', $scope, RoleKey::of('admin', 'manager'),
        fields: ['department' => 'sales', 'weekdays' => [1, 2, 3], 'limit' => 1.5, 'active' => true, 'note' => null]);

    expect($grant->source)->toBe('relation:project')
        ->and($grant->origin)->toBe('manual')
        ->and($grant->role?->full())->toBe('admin:manager')
        ->and($grant->scope)->toBe($scope)
        ->and($grant->expiresAt)->toBeNull()
        ->and($grant->fields())->toBe(['department' => 'sales', 'weekdays' => [1, 2, 3], 'limit' => 1.5, 'active' => true, 'note' => null]);
});

it('is active strictly before its expiry', function (): void {
    $expires = new DateTimeImmutable('2026-10-01 12:00:00');
    $scope = AccessScope::in(TenantRef::global());
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', $scope, expiresAt: $expires);
    $contribution = RoleContribution::of(RoleKey::of('admin', 'manager'), $scope, 'database', 'sync', $expires);
    $forever = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', $scope);

    foreach ([$grant, $contribution] as $value) {
        expect($value->activeAt($expires->modify('-1 second')))->toBeTrue()
            ->and($value->activeAt($expires))->toBeFalse()
            ->and($value->activeAt($expires->modify('+1 second')))->toBeFalse();
    }

    expect($forever->activeAt(new DateTimeImmutable('9999-01-01')))->toBeTrue();
});

it('rejects a grant whose role lives in another panel', function (): void {
    expect(fn () => Grant::of(PermissionPattern::of('admin', 'orders.*'), 'folder', AccessScope::in(TenantRef::global()), RoleKey::of('crm', 'manager')))
        ->toThrow(InvalidSourceContributionException::class, 'Grant role "crm:manager" belongs to another panel than pattern "admin:orders.*".');
});

it('rejects a source or origin outside the label grammar', function (string $source, string $origin): void {
    $scope = AccessScope::in(TenantRef::global());

    expect(fn () => Grant::of(PermissionPattern::of('admin', 'a.b'), $source, $scope, origin: $origin))->toThrow(InvalidIdentityException::class)
        ->and(fn () => RoleContribution::of(RoleKey::of('admin', 'r'), $scope, $source, $origin))->toThrow(InvalidIdentityException::class);
})->with([
    'uppercase source' => ['Folder', 'manual'],
    'empty source' => ['', 'manual'],
    'space in origin' => ['folder', 'host sync'],
    'leading colon origin' => ['folder', ':sync'],
]);

it('rejects fields that are not plain data', function (array $fields): void {
    $scope = AccessScope::in(TenantRef::global());

    expect(fn () => Grant::of(PermissionPattern::of('admin', 'a.b'), 'folder', $scope, fields: $fields))->toThrow(InvalidSourceContributionException::class)
        ->and(fn () => RoleContribution::of(RoleKey::of('admin', 'r'), $scope, 'folder', fields: $fields))->toThrow(InvalidSourceContributionException::class);
})->with([
    'object' => [['at' => new DateTimeImmutable]],
    'nested object' => [['list' => [1, new stdClass]]],
    'int key' => [[0 => 'a']],
    'empty key' => [['' => 'a']],
    'closure' => [['fn' => static fn (): int => 1]],
]);

it('detaches top-level and nested field references from grants and role contributions', function (): void {
    $department = 'sales';
    $priority = 7;
    $labels = ['owner'];
    $fields = ['department' => &$department, 'metadata' => [8 => &$priority, 'labels' => &$labels], 'note' => null];
    $scope = AccessScope::in(TenantRef::global());
    $values = [
        Grant::of(PermissionPattern::of('admin', 'orders.view'), 'folder', $scope, fields: $fields),
        RoleContribution::of(RoleKey::of('admin', 'manager'), $scope, 'folder', fields: $fields),
    ];

    $department = new stdClass;
    $priority = static fn (): bool => true;
    $labels[] = new stdClass;
    $fields['metadata']['added'] = 'later';

    foreach ($values as $value) {
        expect($value->fields())->toBe([
            'department' => 'sales',
            'metadata' => [8 => 7, 'labels' => ['owner']],
            'note' => null,
        ]);
    }
});

it('keeps stored fields unchanged when returned field arrays are modified', function (): void {
    $department = 'sales';
    $priority = 7;
    $labels = ['owner'];
    $fields = ['department' => &$department, 'metadata' => [8 => &$priority, 'labels' => &$labels]];
    $scope = AccessScope::in(TenantRef::global());
    $values = [
        Grant::of(PermissionPattern::of('admin', 'orders.view'), 'folder', $scope, fields: $fields),
        RoleContribution::of(RoleKey::of('admin', 'manager'), $scope, 'folder', fields: $fields),
    ];

    foreach ($values as $value) {
        $returned = $value->fields();
        $returned['department'] = new stdClass;
        $returned['metadata'][8] = new stdClass;
        $returned['metadata']['labels'][] = 'changed';

        expect($value->fields())->toBe(['department' => 'sales', 'metadata' => [8 => 7, 'labels' => ['owner']]]);
    }

    expect($fields)->toBe(['department' => 'sales', 'metadata' => [8 => 7, 'labels' => ['owner']]]);
});

it('describes a role contribution with its owner', function (): void {
    $scope = AccessScope::in(TenantRef::of('org', 1));
    $contribution = RoleContribution::of(RoleKey::of('admin', 'analyst'), $scope, 'relation:project', fields: ['seller' => 5]);

    expect($contribution->role->full())->toBe('admin:analyst')
        ->and($contribution->scope)->toBe($scope)
        ->and($contribution->source)->toBe('relation:project')
        ->and($contribution->origin)->toBe('manual')
        ->and($contribution->expiresAt)->toBeNull()
        ->and($contribution->fields())->toBe(['seller' => 5]);
});

it('lets a restriction only pass or deny with a snake_case reason', function (): void {
    expect(RestrictionResult::pass()->denied())->toBeFalse()
        ->and(RestrictionResult::pass()->reason())->toBeNull()
        ->and(RestrictionResult::deny('user_blocked')->denied())->toBeTrue()
        ->and(RestrictionResult::deny('user_blocked')->reason())->toBe('user_blocked');

    foreach (['', 'UserBlocked', 'user-blocked', '_blocked', 'blocked_', 'user__blocked', 'user blocked'] as $reason) {
        expect(fn () => RestrictionResult::deny($reason))->toThrow(InvalidSourceContributionException::class);
    }
});

it('keeps hook and authority values closed', function (): void {
    expect(array_map(static fn (BeforeResult $r): string => $r->name, BeforeResult::cases()))->toBe(['Continue', 'Deny'])
        ->and(array_map(static fn (PermissionAuthority $a): string => $a->value, PermissionAuthority::cases()))->toBe(['policy', 'grants']);
});
