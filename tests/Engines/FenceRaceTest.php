<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
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

// audits/2026-10-09-consistency-design.md, step 2: one snapshot per source read, no retry, a decision reflects the
// state at read time. Another process commits at the barrier and acknowledges before the reader goes on.
it('V99 reads one snapshot across a process barrier and never retries', function (string $edge, bool $continuous, bool $granted): void {
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
        $version = DatabaseWorld::storage()->state('admin')->version;
        $decision = $engine->decide($panel, DatabaseWorld::request());
        // A revoke before the snapshot (at the autocommit lookup of the cache key) is seen; one inside it is not.
        expect($decision->allowed())->toBe($granted)
            ->and($decision->reason)->toBe($granted ? DecisionReason::Granted : DecisionReason::NotGranted)
            ->and($barriers)->toBe(1)
            ->and($decision->state->version)->toBe($granted ? $version : $version + 1);
    } finally {
        $worker->close();
    }
})->with(['before the snapshot' => ['panel_state', false, false], 'between grants' => ['permission_grants', false, true],
    'after the last read' => ['role_grants', false, true], 'every read changes' => ['role_grants', true, true]])->group('engines');

it('V99 fails closed when another process deletes the catalogue between the catalog and assignment snapshots', function (): void {
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
        expect(app(Authorizer::class)->decide($panel, $request)->reason)->toBe(DecisionReason::ConsistencyError)
            ->and($hit)->toBeTrue();
    } finally {
        $worker->close();
    }
})->group('engines');
