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
            default => 'select count(*) from information_schema.innodb_lock_waits w join information_schema.innodb_trx t on t.trx_id = w.requesting_trx_id where t.trx_mysql_thread_id = '.$connectionId,
        };
        $deadline = microtime(true) + 10;
        do {
            if ($connection->getDriverName() === 'mariadb') {
                // Information-schema transaction snapshots can lag behind this fresh lock wait.
                $status = $observer->query('SHOW ENGINE INNODB STATUS')->fetch(PDO::FETCH_ASSOC)['Status'];
                $waiting = false;
                foreach (explode('---TRANSACTION', $status) as $transaction) {
                    if (str_contains($transaction, 'MariaDB thread id '.$connectionId.',')
                        && str_contains($transaction, 'LOCK WAIT')
                        && str_contains($transaction, '`'.$config['database'].'`.`azg_panel_state`')) {
                        $waiting = true;

                        break;
                    }
                }
            } else {
                $waiting = (int) (isset($observer) ? $observer->query($sql)->fetchColumn() : $connection->selectOne($sql)->n) > 0;
            }

            if ($waiting) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Independent revoke did not enter a server-observed lock wait.');
    }
}
