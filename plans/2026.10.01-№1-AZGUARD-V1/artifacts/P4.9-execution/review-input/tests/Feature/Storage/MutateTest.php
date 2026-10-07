<?php

declare(strict_types=1);

use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $this->storage = app(StorageRegistry::class)->get('default');
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    $schema = $this->storage->connection()->getSchemaBuilder();
    $schema->dropIfExists('azg_probe_rows');
    $schema->create('azg_probe_rows', fn (Blueprint $table) => $table->string('value'));
});

afterEach(function (): void {
    $this->storage->connection()->getSchemaBuilder()->dropIfExists('azg_probe_rows');
    app(StorageSchema::class)->drop('default');
});

it('bumps once for multiple touches and keeps no-op state unchanged', function (): void {
    expect($this->storage->state('admin'))->toBeNull();
    $result = $this->storage->mutate(['admin', 'admin'], function (StorageMutation $mutation): string {
        expect($mutation->isNested())->toBeFalse()->and($mutation->state('admin')->version)->toBe(0);
        $mutation->touch('admin');
        $mutation->touch('admin');

        return 'done';
    });
    $state = $this->storage->state('admin');
    $this->storage->mutate('admin', fn (StorageMutation $mutation) => $mutation->state('admin'));
    expect($result)->toBe('done')->and($state->version)->toBe(1)
        ->and($this->storage->state('admin'))->toEqual($state);
});

it('rolls back rows and state without publishing callbacks', function (): void {
    $called = 0;
    expect(fn () => $this->storage->mutate('admin', function (StorageMutation $mutation) use (&$called): void {
        $mutation->table('probe_rows')->insert(['value' => 'discard']);
        $mutation->touch('admin');
        $mutation->afterCommit(function () use (&$called): void {
            $called++;
        });

        throw new RuntimeException('abort');
    }))->toThrow(RuntimeException::class, 'abort');
    expect($this->storage->state('admin'))->toBeNull()->and($this->storage->table('probe_rows')->count())->toBe(0)->and($called)->toBe(0);
});

it('defers publication until the outer transaction commits and discards outer rollback', function (bool $commit): void {
    $called = 0;
    $db = $this->storage->connection();
    $db->beginTransaction();
    $this->storage->mutate('admin', function (StorageMutation $mutation) use (&$called): void {
        expect($mutation->isNested())->toBeTrue();
        $mutation->table('probe_rows')->insert(['value' => 'pending']);
        $mutation->touch('admin');
        $mutation->afterCommit(function () use (&$called): void {
            $called++;
        });
    });
    expect($called)->toBe(0);
    $commit ? $db->commit() : $db->rollBack();
    expect($called)->toBe($commit ? 1 : 0)->and($this->storage->table('probe_rows')->count())->toBe($commit ? 1 : 0);
    expect($this->storage->state('admin')?->version)->toBe($commit ? 1 : null);
})->with([true, false]);

it('discards a failed nested subtree and merges successful child panels once', function (): void {
    $called = 0;
    $this->storage->mutate('admin', function (StorageMutation $outer) use (&$called): void {
        try {
            $this->storage->mutate(['admin', 'failed'], function (StorageMutation $inner) use (&$called): void {
                $inner->table('probe_rows')->insert(['value' => 'discard']);
                $inner->touch('failed');
                $this->storage->mutate('descendant', fn (StorageMutation $child) => $child->touch('descendant'));
                $inner->afterCommit(function () use (&$called): void {
                    $called++;
                });

                throw new RuntimeException('nested');
            });
        } catch (RuntimeException) {
        }
        $outer->touch('admin');
        $this->storage->mutate(['admin', 'success'], fn (StorageMutation $inner) => [$inner->touch('admin'), $inner->touch('success')]);
    });
    expect($this->storage->state('admin')->version)->toBe(1)->and($this->storage->state('success')->version)->toBe(1)
        ->and($this->storage->state('failed'))->toBeNull()->and($this->storage->state('descendant'))->toBeNull()
        ->and($this->storage->table('probe_rows')->count())->toBe(0)->and($called)->toBe(0);
});

it('retries only concurrency failures with one bump and one publication', function (bool $exhaust): void {
    $attempts = 0;
    $called = 0;
    $work = function (StorageMutation $mutation) use (&$attempts, &$called, $exhaust): void {
        $attempts++;
        $mutation->touch('admin');
        $mutation->afterCommit(function () use (&$called): void {
            $called++;
        });

        if ($attempts === 1 || $exhaust) {
            throw new PDOException('serialization failure', 40001);
        }
    };

    if ($exhaust) {
        expect(fn () => $this->storage->mutate('admin', $work, 2))->toThrow(PDOException::class);
    } else {
        $this->storage->mutate('admin', $work);
    }
    expect($attempts)->toBe(2)->and($called)->toBe($exhaust ? 0 : 1)
        ->and($this->storage->state('admin')?->version)->toBe($exhaust ? null : 1);
})->with([false, true]);

it('rejects an escaped handle and panels outside the locked set', function (): void {
    $handle = $this->storage->mutate('admin', function (StorageMutation $mutation): StorageMutation {
        expect(fn () => $mutation->touch('other'))->toThrow(UnknownPanelException::class);

        return $mutation;
    });
    foreach ([fn () => $handle->touch('admin'), fn () => $handle->state('admin'), fn () => $handle->table('probe_rows'),
        fn () => $handle->isNested(), fn () => $handle->afterCommit(fn () => null)] as $use) {
        expect($use)->toThrow(UnsupportedDirectWriteException::class);
    }
});

it('does not locally retry a mutation inside an existing transaction', function (): void {
    $count = 0;
    $db = $this->storage->connection();
    $db->beginTransaction();

    try {
        $this->storage->mutate('admin', function () use (&$count): void {
            $count++;

            throw new PDOException('serialization failure', 40001);
        });
    } catch (Throwable) {
    } finally {
        $db->rollBack();
    }
    expect($count)->toBe(1);
});

it('never retries a failed publication after a successful commit', function (): void {
    $attempts = 0;
    $work = function (StorageMutation $mutation) use (&$attempts): void {
        $attempts++;
        $mutation->touch('admin');
        $mutation->afterCommit(function (): void {
            throw new PDOException('serialization failure', 40001);
        });
    };
    expect(fn () => $this->storage->mutate('admin', $work))->toThrow(PDOException::class);
    expect($attempts)->toBe(1)->and($this->storage->state('admin')->version)->toBe(1);
});
