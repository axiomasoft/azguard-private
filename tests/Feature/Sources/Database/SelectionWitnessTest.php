<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('preserves all same-scope witnesses while narrowing candidate refs by tenant and context type', function (): void {
    $tenant = TenantRef::of('organization', 'A');
    $ref = AssignmentScopeRef::of('project', '7');
    $scope = AccessScope::in($tenant, $ref);
    DatabaseWorld::insert('role', [DatabaseWorld::row(scope: $scope),
        DatabaseWorld::row(scope: $scope, overrides: ['origin' => 'import']),
        DatabaseWorld::row(scope: AccessScope::in(TenantRef::of('organization', 'B'), $ref)),
        DatabaseWorld::row(scope: AccessScope::in($tenant, AssignmentScopeRef::of('store', '7')))]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', $scope, ['permission' => 'documents.*'])]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source, AccessScope::in($tenant));
    $selection = $source->contextsCovering(SubjectRef::of('user', 1), PermissionKey::of('admin', 'documents.view'), 'project', $frame);

    expect($selection)->toBeInstanceOf(AssignmentScopeSelection::class)->and($selection->isEverywhere())->toBeFalse()
        ->and($selection->refs())->toHaveCount(1)->and($selection->refs()[0]->equals($ref))->toBeTrue()
        ->and($selection->contributions())->toHaveCount(3);
    foreach ($selection->contributions() as $witness) {
        expect($witness->scope->equals($scope))->toBeTrue();
    }
    expect(array_filter($selection->contributions(), fn ($witness) => $witness instanceof RoleContribution))->toHaveCount(2);
});

it('uses everywhere for tenant-wide witnesses without inventing an Allow', function (): void {
    $tenant = TenantRef::of('organization', 'A');
    $scope = AccessScope::in($tenant);
    DatabaseWorld::insert('role', [DatabaseWorld::row(scope: $scope, overrides: ['role' => 'unknown'])]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source, $scope);
    $selection = $source->contextsCovering(SubjectRef::of('user', 1), PermissionKey::of('admin', 'documents.view'), 'project', $frame);

    expect($selection->isEverywhere())->toBeTrue()->and($selection->contributions())->toHaveCount(1)
        ->and($selection->contributions()[0]->role->key())->toBe('unknown');
    $empty = $source->contextsCovering(SubjectRef::of('user', 2), PermissionKey::of('admin', 'documents.view'), 'project', $frame);
    expect($empty)->not->toBeNull()->and($empty->refs())->toBe([])->and($empty->contributions())->toBe([])->and($empty->isEverywhere())->toBeFalse();
});

it('reads every selection witness in one bounded statement and retains the exact budget', function (int $count): void {
    $scope = AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', 7));
    $rows = array_map(static fn (int $index): array => DatabaseWorld::row(scope: $scope, overrides: ['origin' => 'origin-'.$index]), range(1, $count));
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation) use ($rows): void {
        foreach (array_chunk($rows, 50) as $chunk) {
            $mutation->table('role_grants')->insert($chunk);
        }
        $mutation->touch('admin');
    });
    $source = DatabaseSource::make()->rolesOnly();
    [, $frame] = DatabaseWorld::compile($source);
    $statements = [];
    DatabaseWorld::storage()->connection()->listen(static function (QueryExecuted $query) use (&$statements): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'azg_role_grants')) {
            $statements[] = $query->sql;
        }
    });
    $select = fn (): AssignmentScopeSelection => $source->contextsCovering(SubjectRef::of('user', 1), PermissionKey::of('admin', 'documents.view'), 'project', $frame);

    if ($count > 10000) {
        expect($select)->toThrow(InvalidSourceContributionException::class, '10000 assignment witness budget');
    } else {
        $selection = $select();
        expect($selection->refs())->toHaveCount(1)
            ->and($selection->contributions())->toHaveCount($count)
            ->and($selection->contributions()[0]->origin)->toBe('origin-1')
            ->and($selection->contributions()[$count - 1]->origin)->toBe('origin-'.$count);
    }
    expect($statements)->toHaveCount(1);
})->with([501, 10000, 10001]);

it('validates selection lists and preserves witnesses separately from candidate refs', function (): void {
    $ref = AssignmentScopeRef::of('project', '7');
    $witness = Grant::of(PermissionPattern::of('admin', 'documents.view'), 'database', AccessScope::in(TenantRef::global(), $ref));
    $selection = AssignmentScopeSelection::in([$ref], [$witness, $witness]);
    expect($selection->contributions())->toBe([$witness, $witness])
        ->and(AssignmentScopeSelection::everywhere([])->contributions())->toBe([])
        ->and(AssignmentScopeSelection::nowhere()->refs())->toBe([]);
    foreach ([static fn () => AssignmentScopeSelection::in(['bad'], [$witness]),
        static fn () => AssignmentScopeSelection::in(['key' => $ref], [$witness]),
        static fn () => AssignmentScopeSelection::everywhere(['bad']),
        static fn () => AssignmentScopeSelection::everywhere(['key' => $witness])] as $invalid) {
        expect($invalid)->toThrow(InvalidSourceContributionException::class);
    }
});
