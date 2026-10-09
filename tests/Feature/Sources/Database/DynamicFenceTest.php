<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Reads;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Database\InterferingSource;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;

beforeEach(function (): void {
    ConcurrentWriter::open();
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();
    DatabaseWorld::define(label: 'Old');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'reports.export'])]);
});

afterEach(function (): void {
    ConcurrentWriter::close();
    app(StorageSchema::class)->drop('default');
    DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
    Relation::morphMap([], false);
});

it('prepares once and decides at one state when another connection commits during the assignment read', function (): void {
    $observed = null;
    $beforeTimes = [];
    $afterCalls = 0;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(),
        before: function (EvaluationContext $context) use (&$beforeTimes): BeforeResult {
            $beforeTimes[] = $context->now();

            return BeforeResult::Continue;
        },
        after: function (EvaluationContext $context) use (&$observed, &$afterCalls): void {
            $observed = $context;
            $afterCalls++;
        });
    $reads = [];
    $changed = false;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$reads, &$changed): void {
        if (! str_starts_with(strtolower(ltrim($event->sql)), 'select') || $event->connection === DatabaseWorld::storage()->connection()) {
            return;
        }
        foreach (['panel_state', 'permissions', 'permission_grants', 'role_grants'] as $table) {
            if (str_contains($event->sql, 'azg_'.$table.'"')) {
                $reads[] = $table;
            }
        }

        if (! $changed && str_contains($event->sql, 'azg_role_grants')) {
            $changed = true;
            ConcurrentWriter::commit(static function (Connection $connection): void {
                $connection->table('azg_permissions')->update(['label' => 'New']);
                $connection->table('azg_permission_grants')->delete();
                ConcurrentWriter::touch($connection);
            });
        }
    });
    $version = DatabaseWorld::storage()->state('admin')->version;
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $decision = app(Authorizer::class)->decide($panel, $request);
    // Hooks ran once; the catalog snapshot and the assignment snapshot saw the same state (the write committed
    // inside the second one), so the decision is that state's: granted, with the old label.
    expect($observed)->toBeInstanceOf(EvaluationFrame::class)
        ->and($observed->readAttempt->catalog()->get('reports.export')->label)->toBe('Old')
        ->and($beforeTimes)->toHaveCount(1)->and($afterCalls)->toBe(1)
        ->and($reads)->toBe(['panel_state', 'panel_state', 'permissions', 'panel_state', 'permission_grants', 'role_grants'])
        ->and($decision->reason)->toBe(DecisionReason::Granted)->and($decision->state->version)->toBe($version);

});

it('fails closed when the catalog changes between its snapshot and the assignment snapshot', function (): void {
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    $changed = false;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$changed): void {
        if (! $changed && str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, 'azg_permissions"')) {
            $changed = true;
            ConcurrentWriter::commit(static function (Connection $connection): void {
                $connection->table('azg_permissions')->delete();
                ConcurrentWriter::touch($connection);
            });
        }
    });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    expect(app(Authorizer::class)->decide($panel, $request)->reason)->toBe(DecisionReason::ConsistencyError)->and($changed)->toBeTrue();
});

it('never retries a dynamic snapshot read while another connection writes on every read', function (): void {
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    $attempts = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$attempts): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, 'azg_role_grants')) {
            $attempts++;
            ConcurrentWriter::commit(fn (Connection $connection) => ConcurrentWriter::touch($connection));
        }
    });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $decision = app(Authorizer::class)->decide($panel, $request);
    expect($decision->allowed())->toBeTrue()->and($decision->reason)->toBe(DecisionReason::Granted)
        ->and($decision->state)->toBeInstanceOf(StateToken::class)->and($attempts)->toBe(1);
});

it('keeps rolesOnly from consuming direct grants for a dynamic action', function (): void {
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->rolesOnly()->dynamicPermissions());
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $directReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$directReads): void {
        if (str_contains($event->sql, 'azg_permission_grants')) {
            $directReads++;
        }
    });
    expect(app(Authorizer::class)->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)->and($directReads)->toBe(0);
});

it('runs before denial before assignment reads and observes only the accepted decision', function (): void {
    $external = new InterferingSource;
    $afterCalls = 0;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(), additionalSources: [$external],
        before: static fn (): BeforeResult => BeforeResult::Deny,
        after: function () use (&$afterCalls): void {
            $afterCalls++;
        });
    $assignmentReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$assignmentReads): void {
        if (str_contains($event->sql, '_grants')) {
            $assignmentReads++;
        }
    });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $decision = app(Authorizer::class)->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::Hook)->and($assignmentReads)->toBe(0)
        ->and($external->reads)->toBe(0)->and($afterCalls)->toBe(1);
});

it('runs a writing before hook once and decides with its denial', function (): void {
    $beforeCalls = $afterCalls = 0;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(),
        before: function () use (&$beforeCalls): BeforeResult {
            $beforeCalls++;
            DatabaseWorld::storage()->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));

            return BeforeResult::Deny;
        }, after: function () use (&$afterCalls): void {
            $afterCalls++;
        });
    $assignmentReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$assignmentReads): void {
        if (str_contains($event->sql, '_grants')) {
            $assignmentReads++;
        }
    });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $decision = app(Authorizer::class)->decide($panel, $request);
    // A decision reflects the state at read time: a write by a hook does not run the hook again.
    expect($decision->reason)->toBe(DecisionReason::Hook)->and($assignmentReads)->toBe(0)
        ->and($beforeCalls)->toBe(1)->and($afterCalls)->toBe(1);
});

it('reads every source once; a write by a later source does not discard the database read', function (): void {
    $external = new InterferingSource;
    $observed = null;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(), additionalSources: [$external],
        after: function (EvaluationContext $context) use (&$observed): void {
            $observed = $context;
        });
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $decision = app(Authorizer::class)->decide($panel, $request);
    expect($external->reads)->toBe(1)->and($observed->readAttempt->catalog()->get('reports.export')->label)->toBe('Old')
        ->and($observed->matchingGrants())->not->toBe([])->and($decision->reason)->toBe(DecisionReason::Granted);
});

it('pins dynamic metadata and grants to Default while Primary reads its own complete snapshot', function (): void {
    $storage = DatabaseWorld::storage();
    $connection = $storage->connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Independent in-memory read route fixture uses SQLite.');
    }
    $readPdo = new PDO('sqlite::memory:');
    $replica = new SQLiteConnection($readPdo, ':memory:');
    foreach ($connection->getPdo()->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name LIKE 'azg_%'")->fetchAll(PDO::FETCH_COLUMN) as $ddl) {
        $readPdo->exec($ddl);
    }
    $replica->table('azg_storage_state')->insert(['id' => 1, 'schema' => json_encode($storage->schema(), JSON_THROW_ON_ERROR)]);
    $replica->table('azg_panel_state')->insert(['panel' => 'admin', 'version' => 17, 'incarnation' => '01j00000000000000000000000', 'updated_at' => '2040-01-01 00:00:00']);
    $replica->table('azg_permissions')->insert(['panel' => 'admin', 'tenant_key' => TenantRef::global()->key(), 'name' => 'reports.export', 'label' => 'Replica']);
    // The replica has no grants, while Primary has an exact grant and different metadata.
    $connection->setReadPdo($readPdo);
    $connection->setRecordModificationState(true)->useWriteConnectionWhenReading();
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(), reads: Reads::Default);
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $observed = null;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions(), reads: Reads::Default,
        after: function (EvaluationContext $context) use (&$observed): void {
            $observed = $context;
        });
    $decision = app(Authorizer::class)->decide($panel, $request);
    $frame = $observed;
    $catalog = $frame->readAttempt->catalog();
    expect($catalog->get('reports.export')->label)->toBe('Replica')->and($frame->state()->version)->toBe(17)
        ->and($frame->sourceStates['database']->version)->toBe(17)->and($frame->matchingGrants())->toBe([])->and($decision->reason)->toBe(DecisionReason::NotGranted);
    [$primary] = DatabaseWorld::compile(DatabaseSource::make()->dynamicPermissions());
    expect(app(Authorizer::class)->decide($primary, $request)->allowed())->toBeTrue();
});
