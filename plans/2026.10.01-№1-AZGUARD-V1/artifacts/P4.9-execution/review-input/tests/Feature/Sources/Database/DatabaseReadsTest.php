<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('reads exact tenant context pairs with no cartesian expansion or subject panel leakage', function (): void {
    $a = TenantRef::of('organization', 'A');
    $b = TenantRef::of('organization', 'B');
    $x = AssignmentScopeRef::of('project', 'x');
    $y = AssignmentScopeRef::of('project', 'y');
    $ax = AccessScope::in($a, $x);
    $by = AccessScope::in($b, $y);
    DatabaseWorld::insert('role', [DatabaseWorld::row(scope: $ax), DatabaseWorld::row(scope: $by),
        DatabaseWorld::row(scope: AccessScope::in($a, $y)), DatabaseWorld::row(scope: AccessScope::in($b, $x)),
        DatabaseWorld::row(scope: AccessScope::in($a)), DatabaseWorld::row(scope: $ax, overrides: ['subject_type' => 'vendor']),
        DatabaseWorld::row(scope: $ax, overrides: ['subject_id' => '2']), DatabaseWorld::row(scope: $ax, overrides: ['panel' => 'cabinet'])]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', $ax), DatabaseWorld::row('permission', $by),
        DatabaseWorld::row('permission', AccessScope::in($a, $y)), DatabaseWorld::row('permission', AccessScope::in($b, $x))]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source, $ax);
    $queries = [];
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$queries): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, '_grants')) {
            $queries[] = $event->sql;
        }
    });
    $snapshot = $source->readContributions(SubjectRef::of('user', '1'), [$ax, $by], $frame);

    expect($snapshot['roles'])->toHaveCount(2)->and($snapshot['grants'])->toHaveCount(2)
        ->and($snapshot['state'])->toBeInstanceOf(StateToken::class)->and($queries)->toHaveCount(2);
    foreach ([...$snapshot['roles'], ...$snapshot['grants']] as $contribution) {
        expect($contribution->scope->equals($ax) || $contribution->scope->equals($by))->toBeTrue();
    }
});

it('preserves origin expiry raw roles and exact permission patterns', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row(overrides: ['origin' => 'import', 'expires_at' => '2045-01-02 03:04:05']),
        DatabaseWorld::row(overrides: ['role' => 'unknown', 'origin' => 'legacy'])]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'documents.*', 'expires_at' => '2030-01-01 00:00:00'])]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source);
    $roles = DatabaseWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));
    $grants = DatabaseWorld::items($source->grants(SubjectRef::of('user', 1), [$frame->scope()], $frame));

    expect(array_map(fn ($role) => $role->role->key(), $roles))->toContain('editor', 'unknown')
        ->and($roles[0]->origin)->toBe('import')->and($roles[0]->expiresAt->format('c'))->toBe('2045-01-02T03:04:05+00:00')
        ->and($grants)->toHaveCount(1)->and($grants[0]->pattern->local())->toBe('documents.*')
        ->and($grants[0]->activeAt($frame->now()))->toBeFalse();
});

it('never reads permission assignments in rolesOnly mode', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $source = DatabaseSource::make()->rolesOnly();
    [, $frame] = DatabaseWorld::compile($source);
    $permissionReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$permissionReads): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, 'permission_grants')) {
            $permissionReads++;
        }
    });
    $snapshot = $source->readContributions(SubjectRef::of('user', 1), [$frame->scope()], $frame);
    $grants = DatabaseWorld::items($source->grants(SubjectRef::of('user', 1), [$frame->scope()], $frame));

    expect($snapshot['roles'])->toHaveCount(1)->and($snapshot['grants'])->toBe([])->and($grants)->toBe([])->and($permissionReads)->toBe(0);
});

it('converts malformed stored identity to SourceError and never grants from another valid row', function (): void {
    DatabaseWorld::seedSubject();
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission'),
        DatabaseWorld::row('permission', overrides: ['permission' => 'documents.view ', 'origin' => 'invalid'])]);
    DatabaseWorld::compile(DatabaseSource::make());
    $registry = app(PanelRegistry::class);
    $decision = app(Authorizer::class)->decide($registry->get('admin'), DatabaseWorld::request());

    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError);
});
