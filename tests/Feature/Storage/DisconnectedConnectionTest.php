<?php

declare(strict_types=1);

use AzGuard\Panels\Reads;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;
use Illuminate\Support\Facades\DB;

// DB::disconnect() before a fork or between long-running requests closes the PDO; Laravel reopens it on the next
// query. A check reads storage through its own pinned PDO, so it must reopen the connection the same way instead of
// failing closed with source_error.
it('reopens a disconnected authority connection for the next check', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'azguard-disconnect-');
    config()->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    app('db')->purge('testbench');
    app()->forgetInstance(StorageRegistry::class);

    try {
        SubjectWorld::seed();
        SubjectWorld::compile();
        $member = SubjectWorld::member();
        $member->grantPermission('users.delete');

        DB::connection()->disconnect();
        app()->forgetScopedInstances();

        expect($member->guard('admin')->decide('users.delete')->reason->value)->toBe('granted');
    } finally {
        app('db')->purge('testbench');
        unlink($file);
    }
});

// The session pins the PDO on a private clone of the connection; since Laravel 12 a clone and its grammar reference
// each other, so only the cycle collector would free it. A finished session must not keep a disconnected PDO (and the
// server connection behind it) open.
it('releases the pinned handle when a read session ends, so DB::disconnect() closes the connection', function (): void {
    app(StorageSchema::class)->create('default');
    $storage = app(StorageRegistry::class)->get('default');
    $session = $storage->readSession(Reads::Primary);
    $session->table('subject_revisions')->count();
    $pdo = WeakReference::create($storage->connection()->getPdo());

    $storage->connection()->disconnect();
    gc_disable();

    try {
        unset($session);

        expect($pdo->get())->toBeNull();
    } finally {
        gc_enable();
    }
});
