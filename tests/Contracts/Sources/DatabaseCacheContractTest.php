<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Support\Carbon;

it('reuses stable database authority and invalidates it after grant and revoke', function (): void {
    config(['azguard.cache.namespace' => 'source-contract-cache', 'azguard.cache.signing_key' => str_repeat('k', 32)]);
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $check = static function () use ($engine, $panel, &$budget): array {
        $budget = CacheWorld::emptyBudget();
        $decision = $engine->decide($panel, DatabaseWorld::request());

        return [$decision, $budget];
    };

    expect($check()[0]->reason)->toBe(DecisionReason::NotGranted);
    app()->forgetScopedInstances();
    [$warm, $reads] = $check();
    expect($warm->reason)->toBe(DecisionReason::NotGranted)->and($reads['grants'])->toBe(0);

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission'));
        $mutation->touchSubject('admin', SubjectRef::of('user', 1));
    });
    [$granted, $reads] = $check();
    expect($granted->reason)->toBe(DecisionReason::Granted)->and($reads['grants'])->toBeGreaterThan(0);
    app()->forgetScopedInstances();
    [$warm, $reads] = $check();
    expect($warm->reason)->toBe(DecisionReason::Granted)->and($reads['grants'])->toBe(0);

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->where('subject_id', '1')->delete();
        $mutation->touchSubject('admin', SubjectRef::of('user', 1));
    });
    [$revoked, $reads] = $check();
    expect($revoked->reason)->toBe(DecisionReason::NotGranted)->and($reads['grants'])->toBeGreaterThan(0);
});

it('expires a warmed database grant at its boundary without a state mutation', function (): void {
    config(['azguard.cache.namespace' => 'source-contract-expiry', 'azguard.cache.signing_key' => str_repeat('k', 32)]);
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');

    try {
        app(StorageSchema::class)->create('default');
        DatabaseWorld::seedSubject();
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['expires_at' => '2026-01-01 00:01:00'])]);
        [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
        $before = $engine->decide($panel, DatabaseWorld::request());
        expect($before->reason)->toBe(DecisionReason::Granted);
        app()->forgetScopedInstances();
        expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::Granted);
        Carbon::setTestNow('2026-01-01 00:01:00 UTC');
        app()->forgetScopedInstances();
        $after = $engine->decide($panel, DatabaseWorld::request());
        expect($after->reason)->toBe(DecisionReason::NotGranted)
            ->and($after->state->equals($before->state))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});
