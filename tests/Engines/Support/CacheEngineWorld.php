<?php

declare(strict_types=1);

namespace AzGuard\Tests\Engines\Support;

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use PDO;
use RuntimeException;

final class CacheEngineWorld
{
    public static function seed(): void
    {
        $storage = DatabaseWorld::storage();
        app(StorageSchema::class)->drop('default');
        $storage->connection()->getSchemaBuilder()->dropIfExists('users');
        app(StorageSchema::class)->create('default');
        DatabaseWorld::seedSubject();
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    }

    public static function clean(): void
    {
        app(StorageSchema::class)->drop('default');
        DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
        Relation::morphMap([], false);
    }

    public static function waitForLock(int $connectionId): void
    {
        $connection = app('db')->connection('secondary');

        if ($connection->getDriverName() !== 'pgsql') {
            $config = $connection->getConfig();

            if ($config['host'] !== '127.0.0.1' || ! str_ends_with($config['database'], '_test')) {
                throw new RuntimeException('Lock monitor requires a loopback isolated test database.');
            }
            $variable = $connection->getDriverName() === 'mariadb' ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $observer = new PDO('mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$config['database'], 'root', getenv($variable) ?: 'azguard');
        }
        $sql = match ($connection->getDriverName()) {
            'pgsql' => "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid = ".$connectionId,
            'mysql' => 'select count(*) from performance_schema.data_lock_waits w join performance_schema.threads t on t.thread_id = w.requesting_thread_id where t.processlist_id = '.$connectionId,
            default => 'select count(*) from information_schema.innodb_lock_waits w'
                .' join information_schema.innodb_trx t on t.trx_id = w.requesting_trx_id'
                .' join information_schema.innodb_locks l on l.lock_id = w.requested_lock_id'
                .' where t.trx_mysql_thread_id = '.$connectionId." and t.trx_state = 'LOCK WAIT'"
                .' and l.lock_table = '.$observer->quote('`'.$config['database'].'`.`azg_panel_state`'),
        };
        $deadline = microtime(true) + 10;
        do {
            $waiting = (int) (isset($observer) ? $observer->query($sql)->fetchColumn() : $connection->selectOne($sql)->n) > 0;

            if ($waiting) {
                return;
            }
            // MariaDB refreshes its transaction/lock snapshot only after a 100 ms idle interval.
            usleep($connection->getDriverName() === 'mariadb' ? 120000 : 10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Worker connection '.$connectionId.' did not enter a server-observed lock wait.');
    }
}
