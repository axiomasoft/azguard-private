<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeContext;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageTouched;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    $this->connection = CrmWorld::storage()->connection();
});
afterEach(fn () => CrmWorld::resetRuntime());

it('marks a change inside a host transaction pending and commits it with the host', function (bool $commit): void {
    $version = W::version();
    $this->connection->beginTransaction();
    $result = W::grant($this->panel, 'analyst', 2, 1);

    expect($result->committed)->toBeFalse()->and($result->state->version)->toBe($version + 1);
    $commit ? $this->connection->commit() : $this->connection->rollBack();

    expect(W::version())->toBe($commit ? $version + 1 : $version)
        ->and(in_array('crm.organization:1|analyst|2|crm.project:1|manual', W::keys(), true))->toBe($commit);
})->with(['commit' => [true], 'rollback' => [false]]);

it('gives two pipeline calls in one host transaction two storage roots: v+1 and v+2', function (): void {
    $version = W::version();
    [$first, $second] = $this->connection->transaction(fn (): array => [W::grant($this->panel, 'analyst', 2, 1), W::grant($this->panel, 'auditor', 2, null)]);

    expect($first->state->version)->toBe($version + 1)->and($second->state->version)->toBe($version + 2)
        ->and($first->correlationId)->not->toBe($second->correlationId)
        ->and(W::version())->toBe($version + 2);
});

it('joins pipeline calls inside one active storage root into one bump; a no-op child keeps v', function (): void {
    $version = W::version();
    $results = CrmWorld::storage()->mutate('crm', fn (StorageMutation $mutation): array => [
        W::grant($this->panel, 'analyst', 2, 1),
        W::grant($this->panel, 'seller', 1, 1),
        W::grant($this->panel, 'auditor', 2, null),
    ]);

    expect(array_map(fn (ChangeResult $result): array => [$result->status, $result->state->version, $result->committed], $results))->toBe([
        [ChangeStatus::Applied, $version + 1, false],
        [ChangeStatus::Unchanged, $version, false],
        [ChangeStatus::Applied, $version + 1, false],
    ])->and(W::version())->toBe($version + 1);
});

it('discards a failed child subtree inside a storage root, rows and bump included', function (): void {
    $version = W::version();
    $panel = W::panel([static function (Change $c, Closure $next): ChangeResult {
        $result = $next($c);

        if ($c->role?->key() === 'analyst') {
            $c->cancel('after the write');
        }

        return $result;
    }]);
    CrmWorld::storage()->mutate('crm', function () use ($panel): void {
        try {
            W::grant($panel, 'analyst', 2, 1);
        } catch (ChangeCancelledException) {
        }
        expect(W::grant($panel, 'seller', 1, 1)->status)->toBe(ChangeStatus::Unchanged);
    });

    expect(W::version())->toBe($version)->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
});

it('retries the root with a fresh plan, clock, context and pipes after a concurrency error', function (): void {
    $contexts = [];
    $panel = W::panel([static function (Change $c, Closure $next) use (&$contexts): ChangeResult {
        $contexts[] = $c->context();

        if (count($contexts) === 1) {
            throw new QueryException('testbench', 'update azg_panel_state', [], new PDOException('Deadlock found when trying to get lock'));
        }

        return $next($c);
    }]);
    $version = W::version();
    $result = W::grant($panel, 'analyst', 2, 1);

    expect($contexts)->toHaveCount(2)
        ->and($contexts[0])->not->toBe($contexts[1])
        ->and($contexts[0])->toBeInstanceOf(ChangeContext::class)
        ->and($result->effects)->toHaveCount(1)
        ->and($result->state->version)->toBe($version + 1)
        ->and(W::version())->toBe($version + 1);
});

it('keeps a returned result frozen when a listener mutates after commit', function (): void {
    $listened = null;
    Event::listen(StorageTouched::class, function () use (&$listened): void {
        if ($listened === null) {
            $listened = W::grant($this->panel, 'auditor', 2, null);
        }
    });
    $result = W::grant($this->panel, 'analyst', 2, 1);

    expect($listened?->state->version)->toBe($result->state->version + 1)
        ->and(W::version())->toBe($result->state->version + 1)
        ->and($result->records)->toHaveCount(1)->and($result->record?->role?->key())->toBe('analyst');
});

it('refuses writer access outside an active mutation of the panel', function (): void {
    expect(fn () => CrmWorld::storage()->mutation('crm'))->toThrow(UnsupportedDirectWriteException::class)
        ->and(CrmWorld::storage()->mutate('crm', fn (StorageMutation $m): bool => CrmWorld::storage()->mutation('crm') === $m))->toBeTrue()
        ->and(fn () => CrmWorld::storage()->mutate('crm', fn () => CrmWorld::storage()->mutation('backoffice')))->toThrow(UnsupportedDirectWriteException::class);
});
