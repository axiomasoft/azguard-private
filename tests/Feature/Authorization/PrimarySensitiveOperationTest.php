<?php

declare(strict_types=1);

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Context\Internal\CurrentContext;
use AzGuard\Context\Internal\WithinContext;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeSource;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeWorld;

it('keeps the primary choice through request copies and separates cache keys', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Request, reads: Reads::Default);
    $request = DatabaseWorld::request();
    $primary = $request->onPrimary()->inTenant(TenantRef::global())->on(null)->inScope(AccessScope::in(TenantRef::global()))->traced()->withSubjectModel(new User);
    expect($request->requiresPrimary())->toBeFalse()->and($primary->requiresPrimary())->toBeTrue();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $budget = CacheWorld::emptyBudget();
    expect($engine->decide($panel, $request->onPrimary())->allowed())->toBeTrue()
        ->and($budget['grants'])->toBeGreaterThan(0)->and($budget['state'])->toBeGreaterThan(0);
    $budget = CacheWorld::emptyBudget();
    expect($engine->decide($panel, $request->onPrimary())->allowed())->toBeTrue()->and($budget['grants'])->toBe(0);
    expect($panel->settings()->reads())->toBe(Reads::Default);
});

it('carries the override through subject wrappers and all authority lists', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Request, reads: Reads::Default);
    $normal = new SubjectAccess($panel, DatabaseWorld::request()->subject());
    $primary = $normal->onPrimary()->fromOrigin('manual')->inTenant(TenantRef::global());
    expect($normal->hasPermission('documents.view'))->toBeTrue();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($primary->permissionSet()->patterns())->toHaveCount(1)->and($budget['grants'])->toBeGreaterThan(0)
        ->and($primary->hasPermission('documents.view'))->toBeTrue()
        ->and($primary->abilities(['documents.view']))->toBe(['documents.view' => true])
        ->and($primary->permissionSet()->patterns())->toHaveCount(1);
});

it('restores the nested context when a primary-sensitive operation throws', function (): void {
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope('A'))]));
    $within = app(WithinContext::class);
    $contexts = app(CurrentContext::class);
    $outer = ScopeWorld::scope('A');
    $inner = ScopeWorld::scope('B');
    $within->run($panel, $outer, function () use ($engine, $panel, $request, $within, $contexts, $outer, $inner): void {
        expect($engine->decide($panel, $request->onPrimary())->allowed())->toBeTrue();
        expect(fn () => $within->run($panel, $inner, function () use ($engine, $panel, $request): void {
            expect($engine->decide($panel, $request->onPrimary())->allowed())->toBeFalse();

            throw new RuntimeException('operation failed');
        }))->toThrow(RuntimeException::class, 'operation failed');
        expect($contexts->get($panel))->toBe($outer)
            ->and($engine->decide($panel, $request->onPrimary())->allowed())->toBeTrue();
    });
    expect($contexts->get($panel))->toBeNull();
});

it('qualifies roles permissions and superadmin independently across stale read and primary PDOs', function (bool $primaryFirst): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    DatabaseWorld::insert('role', [DatabaseWorld::row(overrides: ['role' => 'root'])]);
    [, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Request, reads: Reads::Default);
    $connection = DatabaseWorld::storage()->connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This split-PDO regression uses an isolated SQLite snapshot.');
    }
    $replica = new PDO('sqlite::memory:');
    $replica->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $primary = $connection->getPdo();
    foreach ($primary->query("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $replica->exec($table['sql']);
        $name = '"'.str_replace('"', '""', $table['name']).'"';
        foreach ($primary->query('select * from '.$name)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insert = $replica->prepare('insert into '.$name.' values ('.implode(',', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
    }
    $normal = new SubjectAccess($panel, DatabaseWorld::request()->subject());
    $sensitive = $normal->onPrimary();
    $sensitive->revokeRole('root');
    $sensitive->revokePermission('documents.view');
    $previous = $connection->getRawReadPdo();
    $connection->setReadPdo($replica);

    try {
        // Repeat both orders on the same wrappers, authorizer and request memo after primary revocation.
        foreach ([$primaryFirst, ! $primaryFirst, $primaryFirst] as $usePrimary) {
            $access = $usePrimary ? $sensitive : $normal;
            expect($access->isSuperAdmin())->toBe(! $usePrimary)
                ->and($access->hasRole('root'))->toBe(! $usePrimary)
                ->and($access->roleNames()->all())->toBe($usePrimary ? [] : ['root'])
                ->and($access->permissionSet()->patterns())->toHaveCount($usePrimary ? 0 : 2);
        }
    } finally {
        $connection->setReadPdo($previous);
    }
})->with([false, true])->group('server-skip');
