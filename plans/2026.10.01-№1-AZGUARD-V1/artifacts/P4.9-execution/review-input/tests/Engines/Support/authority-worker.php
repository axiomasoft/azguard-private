<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\TestCase;

$test = new class('authority-worker') extends TestCase {};
$app = $test->createApplication();
$storage = DatabaseWorld::storage();
echo json_encode(['ready' => true, 'connection_id' => $storage->connection()->selectOne($storage->connection()->getDriverName() === 'pgsql' ? 'select pg_backend_pid() as id' : 'select connection_id() as id')->id])."\n";
fflush(STDOUT);
while (($line = fgets(STDIN)) !== false) {
    try {
        $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        if ($command['mode'] === 'probe') {
            echo json_encode(['version' => $storage->state('admin')->version])."\n";
            fflush(STDOUT);

            continue;
        }
        $storage->mutate('admin', static function (StorageMutation $mutation) use ($command): void {
            $mutation->table('permission_grants')->where('panel', 'admin')->delete();
            $mutation->table('role_grants')->where('panel', 'admin')->delete();

            if ($command['mode'] === 'grant') {
                $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission'));
            }

            if ($command['mode'] === 'dynamic-delete') {
                $mutation->table('permissions')->where('panel', 'admin')->delete();
            }
            $mutation->touch('admin');
        });
        echo json_encode(['committed' => true, 'version' => $storage->state('admin')->version], JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e::class.': '.$e->getMessage()], JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
    }
}
