<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

/*
 * ContributionKey and ObservedState (audits/2026-10-09-consistency-design.md, step 4; acceptance criteria 1, 2, 6, 9).
 * Cached contributions are keyed by storage, incarnation, epoch and the subject's revision, never the panel version;
 * a decision reports the observed state read in one statement, also on a cache hit.
 */

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

/** @return array{Closure(): array{Decision, array{state: int, grants: int, sequence: list<string>}}} */
function contributionDecision(): array
{
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);

    return [static function () use ($engine, $panel, &$budget): array {
        $budget = CacheWorld::emptyBudget();
        $decision = $engine->decide($panel, DatabaseWorld::request());

        return [$decision, $budget];
    }];
}

it('AC1 AC2 keeps the cache key of a subject when another subject is written and reports the current version', function (): void {
    [$check] = contributionDecision();
    [$cold] = $check();
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission', overrides: ['subject_id' => '2']));
        $mutation->touchSubject('admin', SubjectRef::of('user', 2));
    });
    [$hit, $budget] = $check();

    expect($cold->reason)->toBe(DecisionReason::Granted)->and($hit->reason)->toBe(DecisionReason::Granted)
        ->and($budget['grants'])->toBe(0)->and($budget['state'])->toBe(1)
        ->and($hit->state)->toBeInstanceOf(StateToken::class)
        ->and($hit->state->version)->toBe($cold->state->version + 1)
        ->and($hit->state->version)->toBe(DatabaseWorld::storage()->state('admin')->version)
        ->and([$hit->state->epoch, $hit->state->subjectRevision])->toBe([$cold->state->epoch, $cold->state->subjectRevision]);
});

it('AC6 never serves old grants after a completed revoke of the subject or a change of the epoch', function (Closure $write): void {
    [$check] = contributionDecision();
    expect($check()[0]->reason)->toBe(DecisionReason::Granted);
    DatabaseWorld::storage()->mutate('admin', $write);
    [$after, $budget] = $check();

    expect($after->reason)->toBe(DecisionReason::NotGranted)->and($budget['grants'])->toBeGreaterThan(0);
})->with([
    'a revoke of the subject' => [static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->where('subject_id', '1')->delete();
        $mutation->touchSubject('admin', SubjectRef::of('user', 1));
    }],
    'a change of unnamed subjects' => [static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->delete();
        $mutation->touch('admin');
    }],
]);

it('AC9 never publishes a tentative read to the shared cache', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $storage = DatabaseWorld::storage();

    expect(fn () => $storage->mutate('admin', static function (StorageMutation $mutation) use ($engine, $panel): void {
        $mutation->table('permission_grants')->delete();
        $mutation->touchSubject('admin', SubjectRef::of('user', 1));
        // The tentative read sees the uncommitted revoke at the committed revision: the key a publish would use.
        expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);

    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::Granted)
        ->and($budget['grants'])->toBeGreaterThan(0);
});
