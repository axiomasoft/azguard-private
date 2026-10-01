<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class D13RevisionReader extends TestCase
{
    public function test_read(): void {}

    public function bootstrap(): void
    {
        parent::setUp();
    }
}

$reader = new D13RevisionReader('test_read');
$reader->bootstrap();

config()->set('database.connections.testbench', [
    'driver' => 'sqlite',
    'database' => (string) getenv('D13_SQLITE_DATABASE'),
    'prefix' => '',
]);
DB::purge('testbench');
config()->set('database.redis.d13_revision', [
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'database' => 15,
]);
config()->set('cache.stores.d13_revision', [
    'driver' => 'redis',
    'connection' => 'd13_revision',
    'lock_connection' => 'd13_revision',
    'prefix' => (string) getenv('D13_REDIS_PREFIX'),
]);
config()->set('az-guard.cache.store', 'd13_revision');
config()->set('az-guard.cache.expiration_time', 300);
app('cache')->forgetDriver('d13_revision');

$user = User::query()->findOrFail((int) getenv('D13_USER_ID'));
$revision = app(PermissionStateRevision::class)->current();

if ($revision !== (int) getenv('D13_EXPECTED_REVISION')) {
    throw new RuntimeException('Expected revision '.getenv('D13_EXPECTED_REVISION').", got {$revision}.");
}

if ($user->hasPermission('test.post.create', 'test')) {
    throw new RuntimeException('A separate process observed the rolled-back grant.');
}
