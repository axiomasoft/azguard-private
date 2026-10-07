<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\AuthorityProcess;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

beforeEach(fn () => CacheEngineWorld::seed());
afterEach(fn () => CacheEngineWorld::clean());

it('V99 discards every mixed read at a process barrier and retries the whole authority set', function (string $edge, bool $continuous): void {
    $worker = new AuthorityProcess;

    try {
        [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
        $barriers = 0;
        DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $query) use ($worker, $edge, $continuous, &$barriers): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'azg_'.$edge)) {
                return;
            }
            // For panel_state, trigger only T_before (odd numbered state reads).
            static $states = 0;

            if ($edge === 'panel_state' && ++$states % 2 === 0) {
                return;
            }

            if ($continuous || $barriers === 0) {
                $worker->command(['mode' => $barriers % 2 === 0 ? 'revoke' : 'grant']);
                $barriers++;
            }
        });
        $decision = $engine->decide($panel, DatabaseWorld::request());
        expect($decision->allowed())->toBeFalse()
            ->and($decision->reason)->toBe($continuous ? DecisionReason::ConsistencyError : DecisionReason::NotGranted)
            ->and($barriers)->toBe($continuous ? 3 : 1);
    } finally {
        $worker->close();
    }
})->with(['T_before' => ['panel_state', false], 'between grants' => ['permission_grants', false], 'T_after' => ['role_grants', false],
    'all attempts change' => ['role_grants', true]])->group('engines');

it('V99 discards a dynamic Prepare when another process deletes its catalogue and grants', function (): void {
    DatabaseWorld::define();
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'reports.export', 'origin' => 'dynamic'])]);
    $worker = new AuthorityProcess;

    try {
        [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
        $hit = false;
        DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $query) use ($worker, &$hit): void {
            if (! $hit && str_starts_with(strtolower(ltrim($query->sql)), 'select') && preg_match('/azg_permissions["`]/', $query->sql)) {
                $hit = true;
                $worker->command(['mode' => 'dynamic-delete']);
            }
        });
        $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
        expect(fn () => app(Authorizer::class)->decide($panel, $request))->toThrow(UnknownPermissionException::class)
            ->and($hit)->toBeTrue();
    } finally {
        $worker->close();
    }
})->group('engines');
