<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageReadSession;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

/*
 * A DecisionSet reads the observed states of all its subjects in one statement per source and subject type, chunked
 * below the bound-parameter limit of every driver, inside the same snapshot (audits/2026-10-09-consistency-design.md,
 * step 5).
 */

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

uses()->group('batch');

/** @return list<string> */
function revisionSelects(Closure $run): array
{
    $sql = [];
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$sql): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, 'subject_revisions')) {
            $sql[] = $event->sql;
        }
    });
    $run();

    return $sql;
}

it('reads the observed states of every subject of a set in one statement', function (): void {
    [$engine] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $requests = array_map(static fn (int $user): AccessRequest => AccessRequest::for(SubjectRef::of('user', $user),
        PermissionKey::of('admin', DatabasePermission::View->value)), [1, 2, 3, 4, 1]);
    $set = null;

    $selects = revisionSelects(function () use ($engine, $requests, &$set): void {
        $set = $engine->decideMany($requests);
    });

    expect($selects)->toHaveCount(1)
        ->and($set->get(0)->reason)->toBe(DecisionReason::Granted)
        ->and($set->get(1)->reason)->toBe(DecisionReason::NotGranted)
        ->and($set->states())->toHaveCount(1);
});

it('chunks the subject list inside one snapshot and keeps every revision', function (): void {
    $storage = DatabaseWorld::storage();
    $ids = array_map(strval(...), range(1, 2 * StorageReadSession::OBSERVED_CHUNK + 1));
    foreach (array_chunk($ids, 200) as $chunk) {
        $storage->table('subject_revisions')->insert(array_map(static fn (string $id): array => ['panel' => 'admin', 'subject_type' => 'user', 'subject_id' => $id, 'revision' => (int) $id % 7], $chunk));
    }
    $session = $storage->readSession(Reads::Primary);
    $result = null;

    $selects = revisionSelects(function () use ($session, $ids, &$result): void {
        $result = $session->snapshot(static fn (): array => $session->observedMany('admin', 'user', [...$ids, 'missing', '1']));
    });
    [$state, $revisions] = $result;

    expect($selects)->toHaveCount(3)
        ->and($state?->version)->toBe($storage->state('admin')?->version)
        ->and($revisions)->toHaveCount(count($ids))
        ->and($revisions['14'])->toBe(0)
        ->and($revisions['1001'])->toBe(1001 % 7)
        ->and($revisions)->not->toHaveKey('missing');
});
