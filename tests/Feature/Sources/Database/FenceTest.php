<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Reads;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Database\FencedContributions;
use AzGuard\Tests\Fixtures\Sources\Database\FencedDefinitionsOnly;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;

beforeEach(function (): void {
    ConcurrentWriter::open();
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    ConcurrentWriter::close();
    $connection = DatabaseWorld::storage()->connection();
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    app(StorageSchema::class)->drop('default');
    $connection->getSchemaBuilder()->dropIfExists('users');
    Relation::morphMap([], false);
});

it('reads both capabilities in one snapshot while another connection commits a change of both', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source);
    $changes = 0;
    $grantReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$changes, &$grantReads): void {
        if (! str_starts_with(strtolower(ltrim($event->sql)), 'select') || ! str_contains($event->sql, '_grants')) {
            return;
        }
        $grantReads++;

        if (! str_contains($event->sql, 'role_grants') || $changes > 0) {
            return;
        }
        $changes++;
        ConcurrentWriter::commit(function (Connection $connection): void {
            $connection->table('azg_role_grants')->update(['role' => 'unknown']);
            $connection->table('azg_permission_grants')->update(['permission' => 'documents.edit']);
            ConcurrentWriter::touch($connection);
        });
    });
    $version = DatabaseWorld::storage()->state('admin')->version;
    $snapshot = $source->readContributions(SubjectRef::of('user', 1), [$frame->scope()], $frame);

    // The write committed between the two reads; both rows and the state are those of the snapshot, before it.
    expect($changes)->toBe(1)->and($grantReads)->toBe(2)->and($snapshot['roles'][0]->role->key())->toBe('editor')
        ->and($snapshot['grants'][0]->pattern->local())->toBe('documents.view')
        ->and($snapshot['state']->version)->toBe($version)
        ->and(DatabaseWorld::storage()->state('admin')->version)->toBe($version + 1);
});

it('never retries the snapshot read while another connection writes on every read', function (): void {
    DatabaseWorld::seedSubject();
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$panel] = DatabaseWorld::compile(DatabaseSource::make());
    $attempts = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$attempts): void {
        if (! str_starts_with(strtolower(ltrim($event->sql)), 'select') || ! str_contains($event->sql, 'role_grants')) {
            return;
        }
        $attempts++;
        ConcurrentWriter::commit(fn (Connection $connection) => ConcurrentWriter::touch($connection));
    });
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    expect($decision->allowed())->toBeTrue()->and($decision->reason)->toBe(DecisionReason::Granted)->and($attempts)->toBe(1);
});

it('rejects consumed unrecognized framework and raw PDO transactions before assignment reads', function (bool $raw): void {
    $source = DatabaseSource::make();
    [, $frame] = DatabaseWorld::compile($source);
    $connection = DatabaseWorld::storage()->connection();
    $raw ? $connection->getPdo()->beginTransaction() : $connection->beginTransaction();
    $reads = 0;
    $connection->listen(function (QueryExecuted $event) use (&$reads): void {
        if (str_contains($event->sql, '_grants') || str_contains($event->sql, 'panel_state')) {
            $reads++;
        }
    });

    try {
        $source->readContributions(SubjectRef::of('user', 1), [$frame->scope()], $frame);
        test()->fail('Expected authority transaction error.');
    } catch (InvalidConfigurationException $error) {
        expect($error->code())->toBe('invalid_configuration.authority_transaction')->and($reads)->toBe(0);
    } finally {
        $raw ? $connection->getPdo()->rollBack() : $connection->rollBack();
    }
})->with([false, true]);

it('keeps static PolicyOnly free of assignment state dynamic reads and authority transaction guards', function (): void {
    DatabaseWorld::seedSubject();
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    $connection = DatabaseWorld::storage()->connection();
    $connection->beginTransaction();
    $reads = 0;
    $connection->listen(function (QueryExecuted $event) use (&$reads): void {
        if (preg_match('/azg_(role_grants|permission_grants|panel_state|storage_state|permissions)/', $event->sql) === 1) {
            $reads++;
        }
    });

    try {
        $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request(DatabasePermission::Policy));
        expect($decision->allowed())->toBeTrue()->and($decision->reason)->toBe(DecisionReason::Policy)
            ->and($decision->state)->not->toBeInstanceOf(StateToken::class)->and($reads)->toBe(0);
    } finally {
        $connection->rollBack();
    }
});

it('pins Default schema state and both grants to the configured read PDO despite sticky writes', function (): void {
    $storage = DatabaseWorld::storage();
    $connection = $storage->connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Independent in-memory read route fixture uses SQLite.');
    }
    DatabaseWorld::insert('role', [DatabaseWorld::row(overrides: ['role' => 'primary'])]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'documents.edit'])]);
    $readPdo = new PDO('sqlite::memory:');
    $replica = new SQLiteConnection($readPdo, ':memory:');
    foreach ($connection->getPdo()->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name LIKE 'azg_%'")->fetchAll(PDO::FETCH_COLUMN) as $ddl) {
        $readPdo->exec($ddl);
    }
    $replica->table('azg_storage_state')->insert(['id' => 1, 'schema' => json_encode($storage->schema(), JSON_THROW_ON_ERROR)]);
    $replica->table('azg_panel_state')->insert(['panel' => 'admin', 'version' => 17, 'incarnation' => '01j00000000000000000000000', 'updated_at' => '2040-01-01 00:00:00']);
    $replica->table('azg_role_grants')->insert(DatabaseWorld::row());
    $replica->table('azg_permission_grants')->insert(DatabaseWorld::row('permission'));
    $connection->setReadPdo($readPdo);
    $connection->setRecordModificationState(true)->useWriteConnectionWhenReading();
    $source = DatabaseSource::make();
    [, $defaultFrame] = DatabaseWorld::compile($source, reads: Reads::Default);
    $snapshot = $source->readContributions(SubjectRef::of('user', 1), [$defaultFrame->scope()], $defaultFrame);

    expect($snapshot['state']->version)->toBe(17)->and($snapshot['roles'][0]->role->key())->toBe('editor')
        ->and($snapshot['grants'][0]->pattern->local())->toBe('documents.view');
    $primary = DatabaseSource::make();
    [, $primaryFrame] = DatabaseWorld::compile($primary);
    $snapshot = $primary->readContributions(SubjectRef::of('user', 1), [$primaryFrame->scope()], $primaryFrame);
    expect($snapshot['state']->version)->toBe(2)->and($snapshot['roles'][0]->role->key())->toBe('primary')
        ->and($snapshot['grants'][0]->pattern->local())->toBe('documents.edit');
})->group('sqlite');

it('does not consume a fence source with no assignment capabilities', function (): void {
    DatabaseWorld::seedSubject();
    $source = new FencedDefinitionsOnly;
    [$panel] = DatabaseWorld::compile($source);
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    expect($decision->reason)->toBe(DecisionReason::NotGranted)->and($source->stateCalls)->toBe(0);
});

it('fences and retries only the reads of an external source, as a whole set', function (): void {
    DatabaseWorld::seedSubject();
    $source = new FencedContributions;
    [$panel] = DatabaseWorld::compile($source);
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::NotGranted)
        ->and($source->grantCalls)->toBe(2)->and($source->roleCalls)->toBe(2)
        // The read that keys the cache lookup starts the first fence; the retry reads the state before and after.
        ->and($source->stateCalls)->toBe(4);
});
