<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

beforeEach(function (): void {
    ConcurrentWriter::open();
    app(StorageSchema::class)->create('default');
    DatabaseWorld::define(label: 'Old');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'reports.export'])]);
});

afterEach(fn () => ConcurrentWriter::close());

uses()->group('batch');

it('retries stale request state before reading the dynamic catalog and consumes fresh metadata', function (): void {
    $observed = [];
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make()->dynamicPermissions(), configure: function (PanelBuilder $panel) use (&$observed): void {
        $panel->after(function (EvaluationContext $context) use (&$observed): void {
            if ($context->readAttempt !== null) {
                $observed[] = $context;
            }
        });
    });
    $dynamic = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $old = $engine->decide($panel, $dynamic);
    expect($old->allowed())->toBeTrue()->and($old->reason)->toBe(DecisionReason::Granted);
    $observed = [];
    $storage = DatabaseWorld::storage();
    // Simulate an independent committed writer: its revision update sends no local request-memo callback.
    $storage->connection()->transaction(function () use ($storage): void {
        $storage->table('permissions')->update(['label' => 'Fresh']);
        $storage->table('permission_grants')->delete();
        $storage->table('panel_state')->where('panel', 'admin')->increment('version');
    });
    $catalogReads = 0;
    $storage->connection()->listen(function (QueryExecuted $query) use (&$catalogReads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'azg_permissions"')) {
            $catalogReads++;
        }
    });
    $set = $engine->decideMany([$dynamic, DatabaseWorld::request(DatabasePermission::Policy), $dynamic]);
    expect($set->get(0)->reason)->toBe(DecisionReason::NotGranted)->and($set->get(2)->reason)->toBe(DecisionReason::NotGranted)
        ->and($set->get(1)->allowed())->toBeTrue()->and($set->get(1)->state)->toBeInstanceOf(CodeStateToken::class)
        ->and($set->get(0)->state)->toBeInstanceOf(StateToken::class)->and($set->get(0)->state->version)->toBeGreaterThan($old->state->version)
        ->and($catalogReads)->toBe(1)->and($observed)->toHaveCount(2);

    foreach ($observed as $context) {
        expect($context->readAttempt->catalog()->get('reports.export')->label)->toBe('Fresh')
            ->and($context->matchingGrants())->toBe([]);
    }
});

it('reads the dynamic grants of a batch in one snapshot while another connection writes on every read', function (): void {
    [$engine] = CacheWorld::database(DatabaseSource::make()->dynamicPermissions());
    $attempts = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $query) use (&$attempts): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'azg_role_grants"')) {
            $attempts++;
            ConcurrentWriter::commit(fn (Connection $connection) => ConcurrentWriter::touch($connection));
        }
    });
    $dynamic = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.export'));
    $set = $engine->decideMany([$dynamic, DatabaseWorld::request(DatabasePermission::Policy), $dynamic]);
    expect($attempts)->toBe(1)->and($set->get(0)->reason)->toBe(DecisionReason::Granted)
        ->and($set->get(2)->reason)->toBe(DecisionReason::Granted)->and($set->get(1)->allowed())->toBeTrue()
        ->and($set->get(0)->state)->toBeInstanceOf(StateToken::class)->and($set->get(1)->state)->toBeInstanceOf(CodeStateToken::class);
});

it('throws for an originally missing dynamic permission instead of inventing a definition', function (): void {
    [$engine] = CacheWorld::database(DatabaseSource::make()->dynamicPermissions());
    $missing = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'reports.missing'));
    expect(fn () => $engine->decideMany([DatabaseWorld::request(DatabasePermission::Policy), $missing]))->toThrow(UnknownPermissionException::class);
});
