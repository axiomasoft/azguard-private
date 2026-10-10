<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePolicy;
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
            // After the observed states of the set: the grant moves from user 1 to user 2.
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
use AzGuard\Authorization\Authorizer;

it('shares one physical snapshot across logical stores and read modes', function (bool $separateStore, Reads $reads): void {
    $storage = DatabaseWorld::storage();
    $second = $separateStore ? Storage::own(null, 'other_') : $storage;

    if ($separateStore) {
        app(StorageSchema::class)->create($second->id());
    }
    $second->mutate('cabinet', static fn (StorageMutation $mutation) => $mutation->touch('cabinet'));
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->resourcePrefix(false)
            ->permissions([DatabasePermission::class, DatabaseSource::make()])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])->consistency(Reads::Primary, StateRefresh::Check),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->resourcePrefix(false)
            ->permissions([DatabasePermission::class, DatabaseSource::make()->storage($second)])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])->consistency($reads, StateRefresh::Check),
    ]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $engine = app(Authorizer::class);
    $moved = false;
    $storage->connection()->listen(static function (QueryExecuted $event) use (&$moved, $second): void {
        if ($moved || ! str_starts_with(strtolower(ltrim($event->sql)), 'select') || ! str_contains($event->sql, 'permission_grants')) {
            return;
        }
        $moved = true;
        ConcurrentWriter::commit(static function (Connection $writer) use ($second): void {
            $row = (array) $writer->table('azg_permission_grants')->where('panel', 'admin')->first();
            unset($row['id']);
            $row['panel'] = 'cabinet';
            $writer->table('azg_permission_grants')->where('panel', 'admin')->delete();
            $writer->table($second->prefix().'permission_grants')->insert($row);
            foreach (['admin' => 'azg_', 'cabinet' => $second->prefix()] as $panel => $prefix) {
                $writer->table($prefix.'panel_state')->where('panel', $panel)->increment('version');
                $writer->table($prefix.'subject_revisions')->insertOrIgnore(['panel' => $panel, 'subject_type' => 'user', 'subject_id' => '1', 'revision' => 0]);
                $writer->table($prefix.'subject_revisions')->where('panel', $panel)->increment('revision');
            }
        });
    });
    $requests = array_map(static fn (string $panel): AccessRequest => AccessRequest::for(SubjectRef::of('user', 1),
        PermissionKey::of($panel, DatabasePermission::View->value)), ['admin', 'cabinet']);
    $set = $engine->decideMany($requests);

    expect($storage->connection()->getPdo())->toBe($second->connection()->getPdo())
        ->and($moved)->toBeTrue()->and([$set->get(0)->allowed(), $set->get(1)->allowed()])->toBe([true, false]);
    $current = $engine->decideMany($requests);
    expect([$current->get(0)->allowed(), $current->get(1)->allowed()])->toBe([false, true]);
})->with(['shared store' => [false], 'different prefixes' => [true]])
    ->with(['same read mode' => [Reads::Primary], 'different read modes on an unsplit handle' => [Reads::Default]]);
