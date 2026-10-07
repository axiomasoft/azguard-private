<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Reads;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseRoleGrant;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
    DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
    Relation::morphMap([], false);
});

it('preserves binary host identities exact scoped pairs future expiry and decision fields', function (): void {
    $storage = DatabaseWorld::storage();
    $storage->connection()->getSchemaBuilder()->table('azg_role_grants', function (Blueprint $table): void {
        $table->integer('score')->nullable();
    });
    $a = AccessScope::in(TenantRef::of('organization', 'A'), AssignmentScopeRef::of('project', 'Case7'));
    $b = AccessScope::in(TenantRef::of('organization', 'B'), AssignmentScopeRef::of('project', 'case7'));
    DatabaseWorld::insert('role', [
        DatabaseWorld::row(scope: $a, overrides: ['subject_id' => '007', 'score' => 9, 'meta' => '{"weekdays":[1,5],"private_note":"hidden"}', 'expires_at' => '2045-01-02 03:04:05']),
        DatabaseWorld::row(scope: $b, overrides: ['subject_id' => '007', 'score' => 4, 'meta' => '{"weekdays":[2]}']),
        DatabaseWorld::row(scope: AccessScope::in($a->tenant, $b->context), overrides: ['subject_id' => '007']),
        DatabaseWorld::row(scope: AccessScope::in($b->tenant, $a->context), overrides: ['subject_id' => '007']),
        DatabaseWorld::row(scope: $a, overrides: ['subject_id' => '7']),
        DatabaseWorld::row(scope: $a, overrides: ['subject_type' => 'User', 'subject_id' => '007']),
    ]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', $a, ['subject_id' => '007', 'permission' => 'documents.*'])]);
    $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class)->decisionFields(roleGrant: ['score', 'weekdays']);
    [, $frame] = DatabaseWorld::compile($source, $a);
    $queries = [];
    $storage->connection()->listen(function (QueryExecuted $event) use (&$queries): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, '_grants')) {
            $queries[] = $event->sql;
        }
    });
    $snapshot = $source->readContributions(SubjectRef::of('user', '007'), [$a, $b], $frame);

    $tenantA = array_values(array_filter($snapshot['roles'], fn ($role) => $role->scope->equals($a)));
    expect($snapshot['roles'])->toHaveCount(2)->and($snapshot['grants'])->toHaveCount(1)->and($queries)->toHaveCount(2)
        ->and($tenantA)->toHaveCount(1)
        ->and($tenantA[0]->expiresAt->format('c'))->toBe('2045-01-02T03:04:05+00:00')
        ->and($tenantA[0]->fields())->toBe(['score' => 9, 'weekdays' => [1, 5]])
        ->and($snapshot['grants'][0]->pattern->local())->toBe('documents.*');
})->group('engines');

it('fails closed for a trailing-space stored permission instead of normalizing it', function (): void {
    DatabaseWorld::seedSubject();
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission'),
        DatabaseWorld::row('permission', overrides: ['permission' => 'documents.view ', 'origin' => 'bad'])]);
    [$panel] = DatabaseWorld::compile(DatabaseSource::make());
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError);
})->group('engines');

it('never queries direct permission grants in engine rolesOnly mode', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $source = DatabaseSource::make()->rolesOnly();
    [, $frame] = DatabaseWorld::compile($source);
    $permissionQueries = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$permissionQueries): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, 'permission_grants')) {
            $permissionQueries++;
        }
    });
    $snapshot = $source->readContributions(SubjectRef::of('user', 1), [$frame->scope()], $frame);
    expect($snapshot['roles'])->toHaveCount(1)->and($snapshot['grants'])->toBe([])->and($permissionQueries)->toBe(0);
})->group('engines');

it('pins every Default authority query to the configured distinct read PDO on each engine', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $connection = DatabaseWorld::storage()->connection();
    $writePdo = $connection->getPdo();
    $readPdo = app('db')->connection('secondary')->getPdo();
    expect($readPdo)->not->toBe($writePdo);
    $connection->setReadPdo($readPdo)->setRecordModificationState(true)->useWriteConnectionWhenReading();
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source, reads: Reads::Default);
    $tables = [];
    $connection->listen(function (QueryExecuted $event) use (&$tables, $readPdo): void {
        if (! str_starts_with(strtolower(ltrim($event->sql)), 'select')
            || preg_match('/azg_(storage_state|panel_state|role_grants|permission_grants)/', $event->sql, $matches) !== 1) {
            return;
        }
        expect($event->connection->getPdo())->toBe($readPdo)->and($event->connection->getReadPdo())->toBe($readPdo);
        $tables[] = $matches[1];
    });
    $snapshot = $source->readContributions(SubjectRef::of('user', 1), [$frame->scope()], $frame);
    expect($snapshot['roles'])->toHaveCount(1)->and($snapshot['grants'])->toHaveCount(1)
        ->and($tables)->toContain('storage_state', 'panel_state', 'role_grants', 'permission_grants');
})->group('engines');
