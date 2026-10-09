<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

/*
 * A DecisionSet reads storage in a fixed number of statements however many subjects it holds: one schema check per
 * handle, one observed-state statement and one statement per grant table and chunk of subjects, all in one snapshot.
 * The decisions are those of single checks.
 */

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    // Users 1, 3 and 5 hold the permission directly; 2 holds it for another panel.
    DatabaseWorld::insert('permission', array_map(static fn (string $id): array => DatabaseWorld::row('permission', overrides: ['subject_id' => $id]), ['1', '3', '5']));
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['subject_id' => '2', 'panel' => 'other'])]);
});

uses()->group('batch');

/**
 * @param  list<int|string>  $users
 * @return array{list<string>, list<DecisionReason>}
 */
function batchedStatements(array $users): array
{
    [$engine] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $requests = array_map(static fn (int|string $user): AccessRequest => AccessRequest::for(SubjectRef::of('user', $user),
        PermissionKey::of('admin', DatabasePermission::View->value)), $users);
    $sql = [];
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$sql): void {
        $sql[] = $event->sql;
    });
    $set = $engine->decideMany($requests);
    $reasons = array_map(static fn (int $i): DecisionReason => $set->get($i)->reason, array_keys($requests));

    return [array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'azg_'))), $reasons];
}

it('reads the grants of every subject of a set in one statement per table', function (): void {
    [$five, $reasons] = batchedStatements([1, 2, 3, 4, 5]);
    [$fifty] = batchedStatements(range(1, 50));

    expect($five)->toHaveCount(4)
        ->and(count(array_filter($five, static fn (string $sql): bool => str_contains($sql, 'storage_state'))))->toBe(1)
        ->and(count(array_filter($five, static fn (string $sql): bool => str_contains($sql, 'permission_grants'))))->toBe(1)
        ->and(count(array_filter($five, static fn (string $sql): bool => str_contains($sql, 'role_grants'))))->toBe(1)
        ->and($fifty)->toHaveCount(4)
        ->and($reasons)->toBe([DecisionReason::Granted, DecisionReason::NotGranted, DecisionReason::Granted, DecisionReason::NotGranted, DecisionReason::Granted]);
});

it('decides each subject of a set as a single check does', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $requests = array_map(static fn (int $user): AccessRequest => AccessRequest::for(SubjectRef::of('user', $user),
        PermissionKey::of('admin', DatabasePermission::View->value)), [5, 4, 3, 2, 1, 3]);
    $set = $engine->decideMany($requests);

    foreach ($requests as $i => $request) {
        expect($set->get($i)->reason)->toBe($engine->decide($panel, $request)->reason);
    }
});

it('chunks the subjects of a large set and keeps every grant', function (): void {
    config()->set('azguard.decision_sets.max_subjects', DatabaseSource::ASSIGNMENT_SUBJECT_CHUNK + 3);
    app()->forgetInstance(AzGuardConfig::class);
    $ids = array_map(strval(...), range(1, DatabaseSource::ASSIGNMENT_SUBJECT_CHUNK + 3));
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['subject_id' => end($ids)])]);
    [$sql, $reasons] = batchedStatements($ids);

    expect(count(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'permission_grants'))))->toBe(2)
        ->and($reasons[0])->toBe(DecisionReason::Granted)
        ->and($reasons[1])->toBe(DecisionReason::NotGranted)
        ->and(end($reasons))->toBe(DecisionReason::Granted);
});
