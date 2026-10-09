<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

/*
 * Acceptance criterion 5 (audits/2026-10-09-consistency-design.md, step 5): the database reads of a DecisionSet run in
 * one snapshot, so the decisions of all its subjects match one state, even while another connection commits.
 */

beforeEach(function (): void {
    ConcurrentWriter::open();
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

afterEach(fn () => ConcurrentWriter::close());

uses()->group('batch');

function snapshotRequest(int $user): AccessRequest
{
    return AccessRequest::for(SubjectRef::of('user', $user), PermissionKey::of('admin', DatabasePermission::View->value));
}

it('AC5 decides every subject of a set at one snapshot while another connection moves a grant between them', function (bool $warm): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $version = DatabaseWorld::storage()->state('admin')->version;

    if ($warm) {
        // A cached subject keeps its entry; the set still reads its observed state inside the snapshot.
        expect($engine->decide($panel, snapshotRequest(1))->reason)->toBe(DecisionReason::Granted);
    }
    $moved = false;
    $snapshots = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$moved, &$snapshots): void {
        $sql = strtolower(ltrim($event->sql));
        $snapshots += (int) (str_starts_with($sql, 'start transaction') || str_starts_with($sql, 'begin'));

        if (! $moved && str_starts_with($sql, 'select') && str_contains($event->sql, 'azg_subject_revisions')) {
            $moved = true;
            // After the first subject's observed state: the grant moves from user 1 to user 2.
            ConcurrentWriter::commit(static function (Connection $connection): void {
                $connection->table('azg_permission_grants')->where('subject_id', '1')->update(['subject_id' => '2']);
                foreach (['1', '2'] as $id) {
                    $connection->table('azg_subject_revisions')->insertOrIgnore(['panel' => 'admin', 'subject_type' => 'user', 'subject_id' => $id, 'revision' => 0]);
                    $connection->table('azg_subject_revisions')->where('subject_id', $id)->increment('revision');
                }
                ConcurrentWriter::touch($connection);
            });
        }
    });
    $set = $engine->decideMany([snapshotRequest(1), snapshotRequest(2), snapshotRequest(1)]);

    expect($moved)->toBeTrue()
        ->and([$set->get(0)->reason, $set->get(1)->reason, $set->get(2)->reason])->toBe([DecisionReason::Granted, DecisionReason::NotGranted, DecisionReason::Granted])
        ->and($set->states())->toHaveCount(1)
        ->and($set->get(0)->state)->toBeInstanceOf(StateToken::class)
        ->and($set->get(0)->state->version)->toBe($version)
        ->and($set->get(1)->state->version)->toBe($version);
    // The decisions after the commit see the move.
    expect($engine->decideMany([snapshotRequest(1), snapshotRequest(2)])->get(1)->reason)->toBe(DecisionReason::Granted);
})->with(['cold' => [false], 'warm cache for one subject' => [true]]);
