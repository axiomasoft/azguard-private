<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Engines\Support\AuthorityProcess;
use AzGuard\Tests\Engines\Support\BatchEngineWorld;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(fn () => CacheEngineWorld::seed());
afterEach(fn () => CacheEngineWorld::clean());

it('reads a deduplicated context union in bounded chunks inside one snapshot', function (): void {
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->delete();
        foreach ([1, 101] as $id) {
            $scope = AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('store', $id));
            $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission', $scope));
        }
        $mutation->touch('admin');
    });
    $engine = BatchEngineWorld::compile();
    $requests = BatchEngineWorld::requests();
    $requests = [...$requests, $requests[0], $requests[100]];
    $reads = [];
    BatchEngineWorld::listen($reads);

    $set = $engine->decideMany($requests);

    expect($set)->toHaveCount(103)
        ->and(count($set->states()))->toBe(1)
        ->and(array_column($reads['admin'], 'table'))->toHaveCount(6)
        ->and($reads['admin'][0]['table'])->toBe('panel_state')
        ->and($reads['admin'][1]['table'])->toBe('panel_state')
        ->and($reads['admin'][5]['table'])->toBe('role_grants');
    foreach (['permission_grants', 'role_grants'] as $table) {
        $chunks = array_values(array_filter($reads['admin'], static fn (array $read): bool => $read['table'] === $table));
        $contexts = array_merge(...array_column($chunks, 'contexts'));
        expect($chunks)->toHaveCount(2)
            ->and(count($contexts))->toBe(102)
            ->and(count(array_unique($contexts)))->toBe(102);
        foreach ($chunks as $chunk) {
            expect(count($chunk['contexts']))->toBeLessThanOrEqual(100);
        }
    }
    foreach ($set as $index => $decision) {
        expect($decision->allowed())->toBe(in_array($index, [0, 100, 101, 102], true))
            ->and($decision->scope->context->key())->toBe($requests[$index]->context()->key())
            ->and($decision->state->equals($set->get(0)->state))->toBeTrue();
    }
})->group('engines');

it('keeps every chunk in the snapshot when another process revokes between chunks', function (bool $continuous): void {
    DatabaseWorld::storage()->mutate('cabinet', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission', overrides: ['panel' => 'cabinet']));
        $mutation->touch('cabinet');
    });
    $seen = [];
    $reads = [];
    $engine = BatchEngineWorld::compile(after: function (Decision $decision) use (&$seen, &$reads): void {
        $seen[] = [$decision->state->panel, $decision->reason, count($reads[$decision->state->panel])];
    }, cabinet: true);
    $requests = BatchEngineWorld::requests();
    // Interleaving an independent panel also checks the returned request order.
    array_splice($requests, 50, 0, [BatchEngineWorld::cabinetRequest()]);
    $version = DatabaseWorld::storage()->state('admin')->version;
    $worker = new AuthorityProcess;
    $barriers = 0;

    try {
        BatchEngineWorld::listen($reads, function (string $panel, string $table, int $number) use ($worker, $continuous, &$barriers): void {
            if ($panel !== 'admin' || $table !== 'permission_grants' || $number % 2 !== 1
                || (! $continuous && $barriers !== 0)) {
                return;
            }
            // QueryExecuted fires after chunk one's rows have arrived, before chunk two.
            $ack = $worker->command(['mode' => $barriers % 2 === 0 ? 'revoke' : 'grant']);
            expect($ack['committed'])->toBeTrue();
            $barriers++;
        });

        $set = $engine->decideMany($requests);

        // One snapshot, no retry: chunk two is read at the state of chunk one, before the revoke.
        $attempts = 1;
        $reason = DecisionReason::Granted;
        expect($barriers)->toBe(1)->and($set->get(0)->state->version)->toBe($version)
            ->and($set)->toHaveCount(102)
            ->and(count($set->states()))->toBe(2)
            ->and($reads['admin'])->toHaveCount(6 * $attempts)
            ->and($reads['cabinet'])->toHaveCount(4)
            ->and($seen)->toHaveCount(102);
        foreach ($set as $index => $decision) {
            expect($decision->allowed())->toBeTrue()
                ->and($decision->state->panel)->toBe($requests[$index]->permission()->panel());

            if ($index !== 50) {
                expect($decision->reason)->toBe($reason)
                    ->and($decision->state->equals($set->get(0)->state))->toBeTrue();
            }
        }
        foreach ($seen as [$panel, $observedReason, $completedReads]) {
            if ($panel === 'admin') {
                expect($observedReason)->toBe($reason)
                    ->and($completedReads)->toBe(6 * $attempts);
            } else {
                expect($completedReads)->toBe(4);
            }
        }
    } finally {
        $worker->close();
    }
})->with(['one revoke between chunks' => [false], 'a write at every odd chunk' => [true]])->group('engines');
