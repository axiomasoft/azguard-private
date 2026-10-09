<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\DecisionSetTooLargeException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

// decision_sets.max_subjects (owner decision 3 on the open questions of audits/2026-10-09-consistency-design.md): a set
// over the limit is refused before any read, never split into separate snapshots.

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

uses()->group('batch');

/** @param list<int> $users */
function limitRequests(array $users): array
{
    return array_map(static fn (int $user): AccessRequest => AccessRequest::for(SubjectRef::of('user', $user),
        PermissionKey::of('admin', DatabasePermission::View->value)), $users);
}

function limitEngine(int $limit): Authorizer
{
    config()->set('azguard.decision_sets.max_subjects', $limit);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(Authorizer::class);

    return CacheWorld::database(DatabaseSource::make(), StateRefresh::Check)[0];
}

it('defaults to 500 distinct subjects', function (): void {
    expect(app(AzGuardConfig::class)->maxSetSubjects())->toBe(500)->and(AzGuardConfig::DEFAULT_MAX_SET_SUBJECTS)->toBe(500);
});

it('counts distinct subjects, not requests, up to the limit', function (): void {
    $set = limitEngine(2)->decideMany(limitRequests([1, 2, 1, 2, 1]));

    expect($set)->toHaveCount(5);
});

it('refuses a larger set before any query, with the counts and a stable code', function (): void {
    $engine = limitEngine(2);
    $queries = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$queries): void {
        $queries++;
    });

    try {
        $engine->decideMany(limitRequests([1, 2, 3]));
        $this->fail('The set was not refused.');
    } catch (DecisionSetTooLargeException $e) {
        expect([$e->subjects, $e->limit, $e->code()])->toBe([3, 2, 'decision_set_too_large'])
            ->and($e->getMessage())->toContain('split it explicitly')
            ->and($queries)->toBe(0);
    }
});

it('rejects a limit that is not a positive integer', function (mixed $value): void {
    config()->set('azguard.decision_sets.max_subjects', $value);

    expect(fn () => AzGuardConfig::fromRepository(config()))->toThrow(InvalidConfigurationException::class);
})->with(['zero' => [0], 'negative' => [-5], 'text' => ['many']]);
