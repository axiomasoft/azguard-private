<?php

declare(strict_types=1);

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
